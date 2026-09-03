<?php

namespace App\Domain\Notifications;

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
    public function __construct(
        public RecipientType $type,
        public ?User $user = null,
        public ?string $address = null,
        public ?string $name = null,
    ) {}

    public static function user(User $user, RecipientType $type): self
    {
        return new self($type, $user, $user->email, $user->name);
    }

    public static function address(string $address, RecipientType $type, ?string $name = null): self
    {
        return new self($type, null, $address, $name);
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
        return $this->user !== null ? 'user:'.$this->user->id : 'to:'.strtolower((string) $this->address);
    }
}
