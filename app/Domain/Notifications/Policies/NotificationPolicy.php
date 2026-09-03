<?php

namespace App\Domain\Notifications\Policies;

use App\Domain\Notifications\Models\NotificationLog;
use App\Models\User;

/**
 * The matrix, templates and log are administration; the bell is not.
 * Everyone sees their own notifications and their own preferences.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('notifications.view');
    }

    public function view(User $user, NotificationLog $log): bool
    {
        return $user->can('notifications.view');
    }

    public function update(User $user): bool
    {
        return $user->can('notifications.update');
    }

    /**
     * Re-queueing a failed delivery is a change, not a read.
     */
    public function retry(User $user, NotificationLog $log): bool
    {
        return $user->can('notifications.update') && $log->status()->isRetryable();
    }
}
