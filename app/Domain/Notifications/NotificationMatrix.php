<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationSetting;
use Illuminate\Support\Facades\Cache;

/**
 * The admin's event x recipient type x channel switchboard.
 *
 * Only cells that differ from the registry default are stored, so adding a
 * channel to an event's defaults later takes effect for everyone who never
 * touched that cell.
 */
class NotificationMatrix
{
    public const CACHE_KEY = 'notifications:matrix';

    /**
     * @var array<string, bool>|null
     */
    private ?array $overrides = null;

    public function isEnabled(string $eventKey, RecipientType $type, NotificationChannel $channel): bool
    {
        $event = NotificationEventRegistry::find($eventKey);

        if ($event === null || ! $event->allows($type)) {
            return false;
        }

        return $this->overrides()[$this->cell($eventKey, $type, $channel)]
            ?? $event->defaultsTo($channel);
    }

    /**
     * Set one cell, or clear it back to the default when it matches.
     */
    public function set(string $eventKey, RecipientType $type, NotificationChannel $channel, bool $enabled): bool
    {
        $event = NotificationEventRegistry::find($eventKey);

        if ($event === null || ! $event->allows($type)) {
            return false;
        }

        if ($enabled === $event->defaultsTo($channel)) {
            NotificationSetting::query()
                ->where('event', $eventKey)
                ->where('recipient_type', $type->value)
                ->where('channel', $channel->value)
                ->delete();
        } else {
            NotificationSetting::query()->updateOrCreate(
                ['event' => $eventKey, 'recipient_type' => $type->value, 'channel' => $channel->value],
                ['enabled' => $enabled]
            );
        }

        $this->flush();

        return true;
    }

    public function toggle(string $eventKey, RecipientType $type, NotificationChannel $channel): bool
    {
        return $this->set($eventKey, $type, $channel, ! $this->isEnabled($eventKey, $type, $channel));
    }

    /**
     * Every channel switched on for this event and recipient type.
     *
     * @return array<int, NotificationChannel>
     */
    public function channelsFor(string $eventKey, RecipientType $type): array
    {
        return array_values(array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel) => $this->isEnabled($eventKey, $type, $channel)
        ));
    }

    public function flush(): void
    {
        $this->overrides = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, bool>
     */
    private function overrides(): array
    {
        return $this->overrides ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => NotificationSetting::query()
                ->get()
                ->mapWithKeys(fn (NotificationSetting $row) => [
                    $row->event.'|'.$row->recipient_type.'|'.$row->channel => $row->enabled,
                ])
                ->all()
        );
    }

    private function cell(string $eventKey, RecipientType $type, NotificationChannel $channel): string
    {
        return $eventKey.'|'.$type->value.'|'.$channel->value;
    }
}
