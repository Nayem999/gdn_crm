<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;

/**
 * One thing the application can notify about.
 */
readonly class NotificationEvent
{
    /**
     * @param  array<int, RecipientType>  $recipientTypes  Who this event can reach.
     * @param  array<int, NotificationChannel>  $defaultChannels  On until an admin says otherwise.
     * @param  array<string, string>  $mergeFields  Field name => what it holds.
     * @param  array<string, string>  $defaultTemplates  Channel value => body.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public string $description,
        public array $recipientTypes,
        public array $defaultChannels,
        public array $mergeFields = [],
        public ?string $defaultSubject = null,
        public array $defaultTemplates = [],
    ) {}

    public function allows(RecipientType $type): bool
    {
        return in_array($type, $this->recipientTypes, true);
    }

    public function defaultsTo(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->defaultChannels, true);
    }

    /**
     * The body an admin starts from, before they edit it in Settings.
     */
    public function defaultBody(NotificationChannel $channel): string
    {
        return $this->defaultTemplates[$channel->value]
            ?? $this->defaultTemplates['*']
            ?? $this->description;
    }
}
