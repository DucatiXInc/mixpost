<?php

namespace Inovector\Mixpost\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus as Status;
use Inovector\Mixpost\Enums\PublishStage;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Models\PostVersion;
use Throwable;

/** Account-specific, durable recovery before Instagram accepts a media container. */
class InstagramPublishingRecovery
{
    public const KEY = 'peachy_publish_recovery';
    public const WINDOW = 1800;

    public function prepare(Post $post, Account $account): ?SocialProviderResponse
    {
        if (! $this->eligible($post, $account)) { return null; }
        $state = $this->state($post, $account);
        if (! $state || empty($state['pending'])) { return null; }
        if (time() >= $state['expires_at'] || $state['attempts'] >= 12) {
            return new SocialProviderResponse(Status::ERROR, ['Automatic storage recovery could not verify the original media within 30 minutes. The original media was preserved.']);
        }
        if (time() < ($state['next_attempt_at'] ?? 0)) { return $this->waiting($state['next_attempt_at'] - time()); }
        $this->change($post, $account, function (&$options, &$state) {
            $state['attempts']++;
            $state['next_attempt_at'] = time() + min(300, 30 * 2 ** min(4, $state['attempts'] - 1));
        });
        $pending = $state['pending'];
        try {
            $media = Media::withoutGlobalScopes()->where('workspace_id', $post->workspace_id)->findOrFail($pending['media_id']);
            // The fallback also re-verifies a promotion completed before a worker restart.
            $result = app(PublishingMediaFallback::class)->recover($media, $pending['conversion']);
            $this->change($post, $account, function (&$options, &$state) use ($result) {
                $state['events'][] = ['kind' => 'storage_recovered'] + $result;
                $state['pending'] = null;
            });
            $post->unsetRelation('versions');
            return null;
        } catch (Throwable $error) {
            Log::warning('mixpost.instagram_recovery_waiting', ['post_id' => $post->id, 'account_id' => $account->id,
                'media_id' => $pending['media_id'], 'exception' => get_class($error)]);
            return $this->waiting(30);
        }
    }

    public function recover(Post $post, Account $account, SocialProviderResponse $response): ?SocialProviderResponse
    {
        $context = $response->context();
        if (! $this->eligible($post, $account) || ! $response->hasError() || $response->id()
            || ($context['phase'] ?? '') !== 'instagram_container_creation') { return null; }
        $state = $this->state($post, $account);
        if ($state && time() >= $state['expires_at']) { return null; }
        $parser = new PostContentParser($account, $post);
        $collaborators = $parser->getVersionOptions()['collaborators'] ?? [];
        $rejected = self::rejectedCollaborators($context['error'] ?? [], $collaborators);
        if ($rejected) {
            $this->change($post, $account, function (&$options, &$state) use ($account, $rejected) {
                $current = $options[$account->provider]['collaborators'] ?? [];
                if (array_diff($rejected, array_map(fn ($name) => strtolower(ltrim($name, '@')), $current))) {
                    throw new \RuntimeException('Collaborators changed during recovery.');
                }
                $options[$account->provider]['collaborators'] = array_values(array_filter($current,
                    fn ($name) => ! in_array(strtolower(ltrim($name, '@')), $rejected, true)));
                foreach ($rejected as $name) {
                    $state['events'][] = ['kind' => 'collaborator_removed', 'username' => $name, 'notify_workspace' => true];
                }
            });
            $this->retainChildren($post, $account, $context);
            return $this->waiting(2);
        }
        if (! self::storageError($context['error'] ?? []) || ! empty($state['pending'])) { return null; }
        $url = $context['rejected_media_url'] ?? '';
        $content = $parser->getVersionContent();
        $ids = $content[0]['media'] ?? [];
        foreach ($parser->formatMedia($ids) as $media) {
            if ($media->workspace_id !== $post->workspace_id) { continue; }
            $assets = [['conversion' => null, 'url' => $media->getUrl(), 'disk' => $media->disk]];
            foreach ($media->conversions ?? [] as $item) {
                $assets[] = ['conversion' => $item['name'], 'url' => $media->getConversionUrl($item['name']), 'disk' => $item['disk']];
            }
            foreach ($assets as $asset) {
                if ($url === '' || $asset['url'] !== $url || config('filesystems.disks.'.$asset['disk'].'.driver') !== 's3') { continue; }
                $this->change($post, $account, function (&$options, &$state) use ($media, $asset) {
                    $state['pending'] = ['media_id' => (int) $media->id, 'conversion' => $asset['conversion']];
                    $state['events'][] = ['kind' => 'storage_retry', 'media_id' => (int) $media->id];
                });
                $this->retainChildren($post, $account, $context);
                return $this->waiting(2);
            }
        }
        return null;
    }

    public static function rejectedCollaborators(array $error, array $requested): array
    {
        $message = $error['error_user_msg'] ?? '';
        $names = [];
        if ((int) ($error['code'] ?? 0) === 110 && (int) ($error['error_subcode'] ?? 0) === 2207018
            && preg_match('/\AThe following user\(s\) cannot be accessed:\s*([@a-zA-Z0-9_., ]+)\z/', $message, $matches)) {
            $names = preg_split('/[ ,]+/', trim($matches[1]));
        } elseif ((int) ($error['code'] ?? 0) === 210 && (int) ($error['error_subcode'] ?? 0) === 2207066
            && preg_match('/\AThe user (@?[a-zA-Z0-9_.]+(?: *, *@?[a-zA-Z0-9_.]+)*) cannot be tagged on this media\.\z/', $message, $matches)) {
            $names = preg_split('/ *, */', $matches[1]);
        }
        $names = array_values(array_unique(array_map(fn ($name) => strtolower(ltrim($name, '@')), $names)));
        $requested = array_map(fn ($name) => strtolower(ltrim($name, '@')), $requested);
        return $names && ! array_diff($names, $requested) ? $names : [];
    }

    public static function storageError(array $error): bool
    {
        return in_array([(int) ($error['code'] ?? 0), (int) ($error['error_subcode'] ?? 0)], [[9004, 2207052], [-2, 2207003]], true);
    }

    public function state(Post $post, Account $account): array
    {
        $version = $post->versions()->where('account_id', $account->id)->first();
        $state = $version?->options[self::KEY] ?? [];
        return ($state['post_id'] ?? null) === $post->id && ($state['account_id'] ?? null) === $account->id ? $state : [];
    }

    private function eligible(Post $post, Account $account): bool
    {
        return in_array($account->provider, ['instagram', 'instagram_standalone'], true)
            && $account->workspace_id === $post->workspace_id && ! $post->publishedProviderPostIds($account);
    }

    private function waiting(int $seconds): SocialProviderResponse
    {
        return new SocialProviderResponse(Status::PENDING, ['automatic_recovery' => true], retryAfter: max(1, min(300, $seconds)));
    }

    private function retainChildren(Post $post, Account $account, array $context): void
    {
        if (! empty($context['accepted_child_ids'])) {
            $post->storePublishState($account, (new PublishCheckpoint(
                PublishStage::PROCESSING, ['container_ids' => $context['accepted_child_ids'], 'children_ready' => true],
                expiresAt: time() + self::WINDOW,
            ))->toArray());
        }
    }

    private function change(Post $post, Account $account, callable $change): void
    {
        DB::transaction(function () use ($post, $account, $change) {
            DB::table('mixpost_posts')->where('id', $post->id)->lockForUpdate()->first();
            $versions = $post->versions()->lockForUpdate()->get();
            $version = $versions->firstWhere('account_id', $account->id);
            if (! $version) {
                $version = $versions->firstWhere('is_original', true)->replicate();
                $version->account_id = $account->id; $version->is_original = false; $version->synced_with = null;
            }
            $options = $version->options ?? [];
            $state = $options[self::KEY] ?? [];
            if (($state['post_id'] ?? null) !== $post->id || ($state['account_id'] ?? null) !== $account->id) {
                $state = ['id' => (string) Str::uuid(), 'post_id' => $post->id, 'account_id' => $account->id,
                    'expires_at' => time() + self::WINDOW, 'attempts' => 0, 'events' => [], 'pending' => null];
            }
            $change($options, $state);
            $options[self::KEY] = $state;
            $version->options = $options; $version->save();
        });
        $post->unsetRelation('versions');
    }
}
