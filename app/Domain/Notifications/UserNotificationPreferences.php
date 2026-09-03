<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Models\User;

/**
 * A person's own overrides, which win over the admin matrix.
 *
 * They can only ever turn something *off*. Letting a preference switch a channel
 * back on would let someone opt into notifications an administrator disabled for
 * everyone, which is the wrong way round.
 */
class UserNotificationPreferences
{
    public function allows(User $user, string $eventKey, NotificationChannel $channel): bool
    {
        $rows = $this->rowsFor($user);

        // The event-specific row is the more precise instruction, so it beats
        // the channel-wide one either way.
        $specific = $rows[$eventKey.'|'.$channel->value] ?? null;

        if ($specific !== null) {
            return $specific;
        }

        return $rows['*|'.$channel->value] ?? true;
    }

    /**
     * Mute or unmute a whole channel for this person.
     */
    public function setChannel(User $user, NotificationChannel $channel, bool $enabled): void
    {
        $this->store($user, null, $channel, $enabled);
    }

    /**
     * Mute or unmute one event on one channel.
     */
    public function setEvent(User $user, string $eventKey, NotificationChannel $channel, bool $enabled): bool
    {
        if (! NotificationEventRegistry::has($eventKey)) {
            return false;
        }

        $this->store($user, $eventKey, $channel, $enabled);

        return true;
    }

    public function channelIsMuted(User $user, NotificationChannel $channel): bool
    {
        return ($this->rowsFor($user)['*|'.$channel->value] ?? true) === false;
    }

    public function eventIsMuted(User $user, string $eventKey, NotificationChannel $channel): bool
    {
        return ($this->rowsFor($user)[$eventKey.'|'.$channel->value] ?? true) === false;
    }

    /**
     * @return array<string, bool>
     */
    private function rowsFor(User $user): array
    {
        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->mapWithKeys(fn (NotificationPreference $row) => [
                ($row->event ?? '*').'|'.$row->channel => $row->enabled,
            ])
            ->all();
    }

    private function store(User $user, ?string $eventKey, NotificationChannel $channel, bool $enabled): void
    {
        // A channel-wide "on" is just the absence of a mute, so it clears the
        // row. An event-level "on" is a real instruction — "mute in-app, except
        // for this" — so it is stored, and allows() lets it beat the wide mute.
        if ($enabled && $eventKey === null) {
            NotificationPreference::query()
                ->where('user_id', $user->id)
                ->whereNull('event')
                ->where('channel', $channel->value)
                ->delete();

            return;
        }

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'event' => $eventKey, 'channel' => $channel->value],
            ['enabled' => $enabled]
        );
    }
}
