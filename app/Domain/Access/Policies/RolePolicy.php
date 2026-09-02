<?php

namespace App\Domain\Access\Policies;

use App\Domain\Access\PermissionCatalogue;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    /**
     * The Super Admin role is deliberately not editable. It is the guarantee
     * that somebody can always administer the system, so it cannot have its
     * permissions or access level taken away.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.update') && ! $this->isProtected($role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('roles.delete') && ! $this->isProtected($role);
    }

    public function isProtected(Role $role): bool
    {
        return $role->name === PermissionCatalogue::SUPER_ADMIN_ROLE;
    }
}
