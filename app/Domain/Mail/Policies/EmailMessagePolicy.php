<?php

namespace App\Domain\Mail\Policies;

use App\Domain\Mail\Models\EmailMessage;
use App\Models\User;

/**
 * Who may read the delivery log.
 *
 * It is gated on notifications.view rather than a permission of its own: it
 * answers the same question the notification log answers — what did we try to
 * deliver, and what happened — for the one channel where the provider tells us
 * more than "handed over". A second permission for the same question would only
 * be a second thing to forget to grant.
 *
 * There is no delete: the log is the record of what was sent, and a record
 * somebody can tidy is not one.
 */
class EmailMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('notifications.view');
    }

    public function view(User $user, EmailMessage $message): bool
    {
        return $user->can('notifications.view');
    }
}
