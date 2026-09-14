<?php

namespace App\Domain\Meta\Policies;

use App\Domain\Meta\Models\MetaAccount;
use App\Models\User;

/**
 * Who may see and change the Meta connection.
 *
 * No access-level scoping: a connection belongs to the installation, not to the
 * person who happened to authorise it. Everything here is a permission question.
 *
 * Connecting and disconnecting are separate permissions from viewing, and from
 * each other. Disconnecting stops every lead and every message arriving, which
 * is a bigger decision than making a connection in the first place — and a
 * support engineer who needs to read the status should not be one click from
 * turning the company's lead capture off.
 */
class MetaAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('meta.view');
    }

    public function view(User $user, MetaAccount $account): bool
    {
        return $user->can('meta.view');
    }

    public function create(User $user): bool
    {
        return $user->can('meta.connect');
    }

    public function update(User $user, MetaAccount $account): bool
    {
        return $user->can('meta.manage');
    }

    public function delete(User $user, MetaAccount $account): bool
    {
        return $user->can('meta.disconnect');
    }

    /**
     * Running a synchronisation by hand rather than waiting for the schedule.
     */
    public function sync(User $user, MetaAccount $account): bool
    {
        return $user->can('meta.sync');
    }
}
