<?php

namespace App\Livewire\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\UserNotificationPreferences;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A person's own notification preferences, shown in their profile.
 *
 * It only ever writes rows for the signed-in user, so there is nothing here for
 * a tampered request to reach.
 */
class NotificationPreferencesPanel extends Component
{
    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function channelIsMuted(string $channel): bool
    {
        $resolved = NotificationChannel::tryFrom($channel);

        return $resolved !== null
            && app(UserNotificationPreferences::class)->channelIsMuted($this->user(), $resolved);
    }

    public function eventIsMuted(string $eventKey, string $channel): bool
    {
        $resolved = NotificationChannel::tryFrom($channel);

        return $resolved !== null
            && app(UserNotificationPreferences::class)->eventIsMuted($this->user(), $eventKey, $resolved);
    }

    public function toggleChannel(string $channel): void
    {
        $resolved = NotificationChannel::tryFrom($channel);

        if ($resolved === null) {
            return;
        }

        app(UserNotificationPreferences::class)->setChannel(
            $this->user(),
            $resolved,
            $this->channelIsMuted($channel)
        );

        $this->dispatch('preferences-saved');
    }

    public function toggleEvent(string $eventKey, string $channel): void
    {
        $resolved = NotificationChannel::tryFrom($channel);

        if ($resolved === null) {
            return;
        }

        app(UserNotificationPreferences::class)->setEvent(
            $this->user(),
            $eventKey,
            $resolved,
            $this->eventIsMuted($eventKey, $channel)
        );

        $this->dispatch('preferences-saved');
    }

    /**
     * Only the events and channels an administrator has actually switched on
     * are worth offering; muting something already off would be theatre.
     *
     * @return array<int, array{event: NotificationEvent, channels: array<int, NotificationChannel>}>
     */
    public function rows(): array
    {
        $matrix = app(NotificationMatrix::class);
        $rows = [];

        foreach (NotificationEventRegistry::events() as $event) {
            $channels = [];

            foreach ($event->recipientTypes as $type) {
                foreach ($matrix->channelsFor($event->key, $type) as $channel) {
                    $channels[$channel->value] = $channel;
                }
            }

            if ($channels !== []) {
                $rows[] = ['event' => $event, 'channels' => array_values($channels)];
            }
        }

        return $rows;
    }

    public function render(): View
    {
        return view('livewire.notifications.preferences', [
            'channels' => NotificationChannel::cases(),
            'rows' => $this->rows(),
        ]);
    }
}
