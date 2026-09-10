<?php

namespace App\Domain\Audit\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity as AuditEntry;

/**
 * The audit trail, not the Activities module.
 *
 * Named for the entry rather than the class it guards because
 * App\Domain\Activities has an ActivityPolicy of its own, and two classes of
 * that name in one application is how the wrong one gets registered. See
 * .ai/rules/activities.md.
 */
class AuditEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, AuditEntry $entry): bool
    {
        return $user->can('audit.view');
    }
}
