<?php

namespace Inovector\Mixpost\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Inovector\Mixpost\Concerns\Mail;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Enums\PostAccountStatus;
use Inovector\Mixpost\Enums\PostActivityType;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PostFailureExplanation;
use Symfony\Component\Mime\Email;

class PostPublishingRecoveryNotification extends Notification implements QueueWorkspaceAware, ShouldQueue
{
    use Mail, Queueable, SerializesModels;

    public const COLLABORATOR_ALERT_EMAIL = 'dan@ducatix.com';

    public $deleteWhenMissingModels = true;

    public function __construct(public readonly Post $post, public readonly array $recoveries, public readonly bool $failed) {}

    public function via($notifiable): array { return ['mail']; }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $post = $this->post->fresh(['workspace']);
        if (! $post?->workspace) {
            return false;
        }

        if (! $this->failed) {
            return $notifiable instanceof AnonymousNotifiable
                && $notifiable->routeNotificationFor('mail') === self::COLLABORATOR_ALERT_EMAIL
                && $this->publishedRecoveries($post) !== [];
        }

        return $post->isFailed() && ! $post->isScheduleProcessing()
            && ! $post->hasPendingAccounts() && $post->publishing_runs === 0
            && $post->hasErrors();
    }

    public function toMail($notifiable): MailMessage
    {
        $post = $this->post->fresh(['accounts', 'workspace']) ?? $this->post;
        $message = $this->mailNotificationMessage()
            ->subject(($this->failed ? 'Social post needs attention after recovery' : 'Instagram published after removing a collaborator').' ['.substr($post->uuid, 0, 8).']')
            ->greeting($this->failed ? 'Automatic recovery needs attention' : 'Instagram collaborator removal')
            ->line($this->failed ? 'Mixpost attempted automatic recovery, but at least one destination still failed.' : 'Mixpost retried the Instagram destination and published successfully after removing the invitations listed below.');

        if (! $this->failed) {
            $schedulerEmail = $this->schedulerEmail($post);
            if ($schedulerEmail && strcasecmp($schedulerEmail, self::COLLABORATOR_ALERT_EMAIL) !== 0) {
                // The queued mail route is the fallback. Resolve the scheduler using current workspace access at delivery.
                $message->cc(self::COLLABORATOR_ALERT_EMAIL)
                    ->withSymfonyMessage(static fn (Email $email) => $email->to($schedulerEmail));
            }
        }

        foreach ($this->failed ? $this->recoveries : $this->publishedRecoveries($post) as $recovery) {
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
        if ($this->failed) {
            foreach ($post->accounts as $account) {
                if ($account->pivot->errors !== null) {
                    $message->line($account->providerName().' — '.($account->name ?: $account->username).': '.PostFailureExplanation::from($account->pivot->errors));
                }
            }
        }
        return $message->action('View post', url(route('mixpost.posts.edit', ['workspace' => $post->workspace->uuid, 'post' => $post->uuid], false)));
    }

    private function schedulerEmail(Post $post): ?string
    {
        $schedulerId = $post->activities()
            ->where('type', PostActivityType::SCHEDULED->value)
            ->latest('id')
            ->value('user_id');

        if (! $schedulerId) {
            return null;
        }

        return $post->workspace->users()->whereKey($schedulerId)->first()?->email;
    }

    private function publishedRecoveries(Post $post): array
    {
        $accounts = $post->accounts->keyBy('id');

        return array_filter($this->recoveries, function (array $recovery) use ($accounts): bool {
            $pivot = $accounts->get($recovery['account_id'])?->pivot;

            return isset($recovery['published_id']) && $pivot
                && $pivot->status === PostAccountStatus::PUBLISHED->value
                && $pivot->provider_post_id === $recovery['published_id'];
        });
    }
}
