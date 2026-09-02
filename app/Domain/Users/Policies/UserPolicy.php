<?php

namespace App\Domain\Users\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.update');
    }

    /**
     * Nobody may delete their own account from the users screen, whatever their
     * permissions — that would orphan the session doing the deleting.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->can('users.delete') && $user->isNot($model);
    }

    public function invite(User $user): bool
    {
        return $user->can('users.invite');
    }
}
