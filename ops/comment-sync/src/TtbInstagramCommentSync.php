<?php

namespace Inovector\Mixpost\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Enums\Inbox\ConversationType;
use Inovector\Mixpost\Events\Inbox\InboxMessageUpdated;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Inbox\Conversation;
use Inovector\Mixpost\Models\Inbox\Message;

/** Deliberately limited to the approved TTB pilot; no schema changes or pruning. */
final class TtbInstagramCommentSync
{
    use UsesSocialProviderManager;

    public const ACCOUNT = 109;
    public const WORKSPACE = 12;

    public static function applies(Account $account): bool
    {
        return (int) $account->id === self::ACCOUNT
            && (int) $account->workspace_id === self::WORKSPACE
            && $account->provider === 'instagram';
    }

    public function run(): void
    {
        Cache::lock('peachy:ttb-comment-sync:run', 900)->get(function (): void {
            $account = Account::withoutWorkspace()->find(self::ACCOUNT);
            if (! $account || ! self::applies($account) || $account->isUnauthorized() || ! $account->isServiceActive()) {
                return;
            }
            $account->workspace->execute(function () use ($account): void {
                $provider = $this->connectProvider($account);
                if (! method_exists($provider, 'fetchCommentSnapshot')) {
                    return;
                }
                $cursor = (int) Cache::get('peachy:ttb-comment-sync:cursor', 0);
                $conversations = Conversation::withoutWorkspace()
                    ->where('workspace_id', self::WORKSPACE)->where('account_id', self::ACCOUNT)
                    ->where('provider', 'instagram')->where('type', ConversationType::COMMENT)
                    ->whereNotNull('provider_post_id')->where('id', '>', $cursor)->orderBy('id')->limit(10)->get();
                if ($conversations->isEmpty()) {
                    Cache::forget('peachy:ttb-comment-sync:cursor');
                    return;
                }
                $budget = 100;
                $deadline = microtime(true) + 240;
                foreach ($conversations as $conversation) {
                    if ($budget < 1 || microtime(true) >= $deadline) {
                        break;
                    }
                    $result = $this->reconcile($conversation, function () use ($provider, $conversation, &$budget): array {
                        $snapshot = $provider->fetchCommentSnapshot($conversation->provider_post_id, $budget);
                        $budget -= $snapshot['requests'];
                        return $snapshot;
                    });
                    Cache::forever('peachy:ttb-comment-sync:cursor', $conversation->id);
                    Log::info('peachy.ttb_comment_sync', ['account_id' => self::ACCOUNT, 'conversation_id' => $conversation->id] + $result);
                    // Stop this batch on failures/caps, especially throttling; retry on a later rotation.
                    if (! $result['complete']) {
                        break;
                    }
                }
            });
        });
    }

    public function reconcile(Conversation $conversation, Closure $fetch, ?int $now = null): array
    {
        $now ??= time();
        if ((int) $conversation->account_id !== self::ACCOUNT || (int) $conversation->workspace_id !== self::WORKSPACE
            || $conversation->provider !== 'instagram' || $conversation->type !== ConversationType::COMMENT) {
            return ['complete' => false, 'changed' => 0, 'skipped' => true];
        }
        // Capture before HTTP; never classify a new or concurrently edited message from a stale scan.
        $before = Message::withoutWorkspace()->where('conversation_id', $conversation->id)
            ->where('account_id', self::ACCOUNT)->where('workspace_id', self::WORKSPACE)->where('provider', 'instagram')->get();
        $snapshot = $fetch();
        $complete = ($snapshot['complete'] ?? false) === true;
        $present = array_fill_keys(array_map('strval', $snapshot['ids'] ?? []), true);
        $changed = DB::transaction(function () use ($before, $present, $complete, $now): int {
            $changed = 0;
            foreach ($before as $old) {
                if (! preg_match('/^[0-9]{1,32}$/D', (string) $old->provider_message_id)
                    || ! $old->created_at || $old->created_at->timestamp > $now - InstagramCommentAvailability::GRACE_SECONDS) {
                    continue;
                }
                $message = Message::withoutWorkspace()->whereKey($old->id)->lockForUpdate()->first();
                if (! $message || $message->getRawOriginal() !== $old->getRawOriginal()) {
                    continue;
                }
                $data = InstagramCommentAvailability::next($message->data, isset($present[(string) $message->provider_message_id]), $complete, $now);
                if ($data === $message->data) {
                    continue;
                }
                $wasUnavailable = ($message->data[InstagramCommentAvailability::KEY]['state'] ?? null) === 'unavailable';
                $message->data = $data;
                $message->save();
                $isUnavailable = ($data[InstagramCommentAvailability::KEY]['state'] ?? null) === 'unavailable';
                if ($wasUnavailable !== $isUnavailable) {
                    DB::afterCommit(fn () => InboxMessageUpdated::dispatch($message));
                }
                $changed++;
            }
            return $changed;
        });
        return ['complete' => $complete, 'changed' => $changed, 'requests' => (int) ($snapshot['requests'] ?? 0)];
    }

    /** A verified deletion webhook is stronger evidence than absence; still retain the history. */
    public function preserveDeletion(Account $account, string $providerMessageId): bool
    {
        if (! self::applies($account)) {
            return false;
        }
        DB::transaction(function () use ($providerMessageId): void {
            $message = Message::withoutWorkspace()->where('account_id', self::ACCOUNT)->where('workspace_id', self::WORKSPACE)
                ->where('provider', 'instagram')->where('provider_message_id', $providerMessageId)->lockForUpdate()->first();
            if (! $message || ($message->data[InstagramCommentAvailability::KEY]['source'] ?? null) === 'webhook') {
                return;
            }
            // Instagram deletion events apply only to comment threads, never direct messages.
            if ($message->conversation?->type !== ConversationType::COMMENT) {
                return;
            }
            $data = $message->data ?? [];
            $data[InstagramCommentAvailability::KEY] = [
                'state' => 'unavailable', 'source' => 'webhook', 'observed_at' => time(),
            ];
            $message->data = $data;
            $message->save();
            DB::afterCommit(fn () => InboxMessageUpdated::dispatch($message));
        });
        return true;
    }
}
