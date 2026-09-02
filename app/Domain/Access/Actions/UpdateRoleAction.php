<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\DTOs\RoleData;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UpdateRoleAction
{
    /**
     * @throws RuntimeException when asked to change the protected Super Admin role
     */
    public function __invoke(Role $role, RoleData $data): Role
    {
        if ($role->name === PermissionCatalogue::SUPER_ADMIN_ROLE) {
            throw new RuntimeException('The '.PermissionCatalogue::SUPER_ADMIN_ROLE.' role cannot be changed.');
        }

        return DB::transaction(function () use ($role, $data) {
            $before = [
                'name' => $role->name,
                'data_access_level' => (string) $role->getAttribute('data_access_level'),
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ];

            $role->update([
                'name' => $data->name,
                'data_access_level' => $data->dataAccessLevel->value,
            ]);

            $role->syncPermissions(PermissionResolver::models($data->permissions));

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            AuditLogger::updated($role, 'Role', [
                'name' => $data->name,
                'data_access_level' => $data->dataAccessLevel->value,
                'permissions' => collect(PermissionCatalogue::only($data->permissions))->sort()->values()->all(),
            ], $before);

            return $role->refresh();
        });
    }
}
