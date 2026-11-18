<?php

namespace Inovector\Mixpost\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Inovector\Mixpost\Concerns\Mail;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PostFailureExplanation;

class PostPublishingRecoveryNotification extends Notification implements QueueWorkspaceAware, ShouldQueue
{
    use Mail, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public function __construct(public readonly Post $post, public readonly array $recoveries, public readonly bool $failed) {}

    public function via($notifiable): array { return ['mail']; }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $this->failed) { return false; }
        $post = $this->post->fresh(['workspace']);
        return $post !== null && $post->workspace !== null
            && $post->isFailed() && ! $post->isScheduleProcessing()
            && ! $post->hasPendingAccounts() && $post->publishing_runs === 0
            && $post->hasErrors();
    }

    public function toMail($notifiable): MailMessage
    {
        $post = $this->post->fresh(['accounts', 'workspace']) ?? $this->post;
        $message = $this->mailNotificationMessage()
            ->subject(($this->failed ? 'Social post needs attention after recovery' : 'Social post published after automatic recovery').' ['.substr($post->uuid, 0, 8).']')
            ->greeting($this->failed ? 'Automatic recovery needs attention' : 'Your post published after automatic recovery')
            ->line($this->failed ? 'Mixpost attempted automatic recovery, but at least one destination still failed.' : 'Mixpost recovered automatically and completed publishing. No retry is needed.');
        foreach ($this->recoveries as $recovery) {
            $account = $post->accounts->firstWhere('id', $recovery['account_id']);
            $identity = $account ? $account->providerName().' — '.($account->name ?: $account->username) : 'Social account';
            foreach ($recovery['events'] as $event) {
                if ($event['kind'] === 'collaborator_removed') {
                    $message->line($identity.': Instagram rejected collaborator @'.$event['username'].'. Mixpost removed only that invitation, preserved the caption and other collaborators, and continued publishing.');
                } elseif ($event['kind'] === 'storage_recovered') {
                    $message->line($identity.': Instagram could not download media '.$event['media_id'].' from cloud storage. Mixpost recovered identical bytes, verified the public copy, and continued using its own media URL.');
                } elseif ($event['kind'] === 'storage_retry' && ! collect($recovery['events'])->contains(fn ($e) => $e['kind'] === 'storage_recovered' && $e['media_id'] === $event['media_id'])) {
                    $message->line($identity.': Mixpost retried storage recovery for media '.$event['media_id'].' but could not verify a usable copy. The original was preserved.');
                }
            }
        }
        foreach ($post->accounts as $account) {
            if ($account->pivot->errors !== null) {
                $message->line($account->providerName().' — '.($account->name ?: $account->username).': '.PostFailureExplanation::from($account->pivot->errors));
            }
        }
        return $message->action('View post', url(route('mixpost.posts.edit', ['workspace' => $post->workspace->uuid, 'post' => $post->uuid], false)));
    }
}
