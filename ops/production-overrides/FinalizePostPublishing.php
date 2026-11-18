<?php

namespace Inovector\Mixpost\Actions\Post;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Notifications\PostPublishingFailedNotification;
use Inovector\Mixpost\Notifications\PostPublishingRecoveryNotification;
use Inovector\Mixpost\Support\InstagramPublishingRecovery;
use Throwable;
use Inovector\Mixpost\Events\Post\PostPublishedAll;
use Inovector\Mixpost\Events\Post\PostPublishProgress;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Post;

/**
 * Settles a post once every account has been through the publishing pipeline. Shared by the initial
 * publishing batch and by a per-account retry so both arrive at the same status.
 */
class FinalizePostPublishing
{
    /**
     * Batch callbacks run as plain queued closures, so no workspace is bound to the worker and every
     * workspace-scoped query would otherwise resolve against whichever workspace the previous job on
     * that worker happened to leave behind.
     */
    public static function callback(Post $post, int $workspaceId): Closure
    {
        return function (Batch $batch) use ($post, $workspaceId) {
            if (! $workspace = WorkspaceManager::findById($workspaceId)) {
                return;
            }

            WorkspaceManager::setCurrent($workspace);

            (new self)($post, $batch->hasFailures());
        };
    }

    public function __invoke(Post $post, bool $batchHadFailures = false): void
    {
        $post->withPublishingLock(function () use ($post, $batchHadFailures) {
            if ($post->endPublishingRun() > 0) {
                PostPublishProgress::dispatch($post);

                return;
            }

            // Read again under the lock: the copy the batch carried may predate a run that finished
            // meanwhile and moved the post on.
            $post = $post->fresh();

            if (! $post) {
                return;
            }

            $this->settle($post, $batchHadFailures);
        });
    }

    protected function settle(Post $post, bool $batchHadFailures = false): void
    {
        // A staggered post comes back here after every departure. Settling it on the first one
        // would publish the rest of its accounts never: the runner only looks at posts that are
        // still scheduled.
        if ($post->hasPendingAccounts()) {
            $post->setSchedulePending();

            PostPublishProgress::dispatch($post);

            return;
        }

        if ($post->hasErrors() || $batchHadFailures) {
            $post->setFailed();

            try {
                if (! $this->notifyRecovery($post, true)) {
                    Notification::route('mail', 'socials@ducatix.com')->notify(
                        (new PostPublishingFailedNotification($post, $batchHadFailures))->delay(now()->addSeconds(10))
                    );
                }
                Log::info('mixpost.publish_failure_notification_queued', ['post_id' => $post->id]);
            } catch (Throwable $exception) {
                report($exception);
            }

            PostPublishProgress::dispatch($post);

            return;
        }

        if ($post->isScheduleProcessing()) {
            $post->setPublished();

            PostPublishedAll::dispatch($post);
        }

        PostPublishProgress::dispatch($post);
    }

    private function notifyRecovery(Post $post, bool $failed): bool
    {
        if (! $failed || ! $post->exists) { return false; }
        $versions = $post->versions()->get();
        $recoveries = [];
        $hadRecovery = false;
        $outcome = hash('sha256', json_encode([$failed, $post->accounts()->get()->map(fn ($account) => [
            $account->id, $account->pivot->status, $account->pivot->provider_post_id, $account->pivot->errors,
        ])->all()]));
        foreach ($versions as $version) {
            $state = $version->options[InstagramPublishingRecovery::KEY] ?? [];
            if (($state['post_id'] ?? null) !== $post->id || ($state['account_id'] ?? null) !== $version->account_id || empty($state['events'])) { continue; }
            $hadRecovery = true;
            if (($state['notified_outcome'] ?? null) !== $outcome) { $recoveries[] = $state; }
        }
        if (! $recoveries) { return $hadRecovery; }
        Notification::route('mail', 'socials@ducatix.com')->notify(
            (new PostPublishingRecoveryNotification($post, $recoveries, $failed))->delay(now()->addSeconds(10))
        );
        foreach ($versions as $version) {
            $options = $version->options;
            if (collect($recoveries)->contains('id', $options[InstagramPublishingRecovery::KEY]['id'] ?? null)) {
                $options[InstagramPublishingRecovery::KEY]['notified_at'] = gmdate('c');
                $options[InstagramPublishingRecovery::KEY]['notified_outcome'] = $outcome;
                $version->options = $options; $version->save();
            }
        }
        return true;
    }
}
