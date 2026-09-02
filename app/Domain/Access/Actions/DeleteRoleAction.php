<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\PermissionCatalogue;
use RuntimeException;
use Spatie\Permission\Models\Role;

class DeleteRoleAction
{
    /**
     * Remove a role. Users keep their accounts but lose whatever that role
     * granted, dropping to the most restrictive access level.
     *
     * @throws RuntimeException when the role is protected or still assigned
     */
    public function __invoke(Role $role): void
    {
        if ($role->name === PermissionCatalogue::SUPER_ADMIN_ROLE) {
            throw new RuntimeException('The '.PermissionCatalogue::SUPER_ADMIN_ROLE.' role cannot be removed.');
        }

        $assigned = $role->users()->count();

        if ($assigned > 0) {
            throw new RuntimeException(
                'This role is still assigned to '.$assigned.' '.str('user')->plural($assigned).'. Reassign them first.'
            );
        }

        $role->delete();
    }
}
