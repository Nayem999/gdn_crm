<?php

namespace App\Domain\Users\Notifications;

use App\Domain\Users\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $plainToken  The unhashed token, only ever held in transit.
     */
    public function __construct(
        public UserInvitation $invitation,
        public string $plainToken,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->invitation->invitedBy?->name;

        return (new MailMessage)
            ->subject('You have been invited to '.config('app.name'))
            ->greeting($this->invitation->name ? 'Hello '.$this->invitation->name.',' : 'Hello,')
            ->line($inviter
                ? $inviter.' has invited you to join '.config('app.name').'.'
                : 'You have been invited to join '.config('app.name').'.')
            ->action('Accept invitation', route('invitations.accept', ['token' => $this->plainToken]))
            ->line('This invitation expires on '.$this->invitation->expires_at->toDayDateTimeString().'.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
