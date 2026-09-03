<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Enums\RecipientType;
use App\Models\User;

/**
 * The one recipient resolver the engine can own: people who administer an area,
 * found by the permission that grants it.
 *
 * Everything else — a record's customer, its assigned agent, its watchers — is
 * the owning module's business, and is passed in.
 */
class AdminRecipients
{
    /**
     * @return array<int, Recipient>
     */
    public function holding(string $permission, ?User $excluding = null): array
    {
        return User::query()
            ->whereNotNull('email_verified_at')
            ->get()
            ->filter(fn (User $user) => $user->can($permission))
            ->reject(fn (User $user) => $excluding !== null && $user->is($excluding))
            ->map(fn (User $user) => Recipient::user($user, RecipientType::Admin))
            ->values()
            ->all();
    }
}
