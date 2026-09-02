<?php

namespace App\Domain\Teams\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('teams.view');
    }

    public function view(User $user, Team $team): bool
    {
        return $user->can('teams.view');
    }

    public function create(User $user): bool
    {
        return $user->can('teams.create');
    }

    public function update(User $user, Team $team): bool
    {
        return $user->can('teams.update');
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->can('teams.delete');
    }
}
