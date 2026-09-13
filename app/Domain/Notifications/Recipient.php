<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Models\User;

/**
 * One person a notification is going to, and the capacity they are in.
 *
 * The engine never works out who a record's watchers or assigned agent are —
 * only the module owning that record knows. Callers hand recipients in.
 */
readonly class Recipient
{
    /**
     * @param  string|null  $address  Where to reach them by email, or null for a user's own address.
     * @param  string|null  $phone  Where to reach them by SMS or WhatsApp, when that is a different place.
     */
    public function __construct(
        public RecipientType $type,
        public ?User $user = null,
        public ?string $address = null,
        public ?string $name = null,
        public ?string $phone = null,
    ) {}

    public static function user(User $user, RecipientType $type): self
    {
        return new self($type, $user, $user->email, $user->name);
    }

    public static function address(string $address, RecipientType $type, ?string $name = null, ?string $phone = null): self
    {
        return new self($type, null, $address, $name, $phone);
    }

    /**
     * Where this recipient is reached on one channel.
     *
     * An address and a telephone number are not interchangeable, and a
     * recipient who is not a user carries both separately — a customer contact
     * has an email and a mobile, and posting the email to the SMS provider
     * would be a delivery failure at best. A user's own columns are read by
     * NotificationMessage::destination(), so null here means "their own".
     */
    public function addressFor(NotificationChannel $channel): ?string
    {
        return match ($channel) {
            NotificationChannel::Sms, NotificationChannel::WhatsApp => $this->phone
                ?? ($this->user === null ? null : $this->user->phone),
            default => $this->address ?? ($this->user === null ? null : $this->user->email),
        };
    }

    /**
     * Whether this recipient can be reached on a channel at all.
     *
     * In-app needs an account to hang the notification off, and a customer
     * contact does not have one; email needs an address and SMS a number, and
     * a contact may have only one of the two. Asked before anything is queued,
     * so an unreachable combination is skipped and logged rather than failing
     * in the job with a stack trace.
     */
    public function canReceive(NotificationChannel $channel): bool
    {
        if ($channel === NotificationChannel::InApp) {
            return $this->user !== null;
        }

        $address = $this->addressFor($channel);

        return $address !== null && trim($address) !== '';
    }

    public function displayName(): string
    {
        return $this->name
            ?? ($this->user !== null ? $this->user->name : null)
            ?? $this->address
            ?? 'Unknown';
    }

    /**
     * A stable key so the same person is not notified twice for one event, even
     * if they turn up as both an admin and a watcher.
     */
    public function identity(): string
    {
        if ($this->user !== null) {
            return 'user:'.$this->user->id;
        }

        // The phone when there is no address: a contact we hold only a mobile
        // number for still has to be one person, not an empty key every such
        // recipient collides on.
        return 'to:'.strtolower((string) ($this->address ?? $this->phone));
    }
}
