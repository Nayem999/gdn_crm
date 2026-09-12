<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Models\User;

/**
 * One rendered notification, ready for a driver to deliver.
 */
readonly class NotificationMessage
{
    /**
     * @param  array<string, scalar|null>  $data  The merge data it was rendered from.
     */
    public function __construct(
        public string $event,
        public NotificationChannel $channel,
        public RecipientType $recipientType,
        public ?User $user,
        public ?string $address,
        public ?string $subject,
        public string $body,
        public array $data = [],
        public ?string $url = null,
    ) {}

    /**
     * Where this should be delivered: an explicit address, or the user's own.
     */
    public function destination(): ?string
    {
        if ($this->address !== null && $this->address !== '') {
            return $this->address;
        }

        return match ($this->channel) {
            NotificationChannel::Email => $this->user?->email,
            NotificationChannel::InApp => $this->user === null ? null : (string) $this->user->id,
            // A colleague's own number. A customer's arrives as an explicit
            // address instead, because a contact is not a user.
            NotificationChannel::Sms, NotificationChannel::WhatsApp => $this->user?->phone,
        };
    }
}
