<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Shared\Enums\DataAccessLevel;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Write the permission catalogue into the database and keep the Super Admin
 * role holding all of it.
 *
 * Idempotent: this is how permissions added by a new module reach the protected
 * role, and how the very first account is guaranteed a role to be assigned.
 */
final class SyncPermissionCatalogueAction
{
    public function execute(): Role
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = Guard::getDefaultName(Permission::class);

        $permissions = array_map(
            fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => $guard]),
            PermissionCatalogue::all()
        );

        /** @var Role $superAdmin */
        $superAdmin = Role::query()->firstOrCreate([
            'name' => PermissionCatalogue::SUPER_ADMIN_ROLE,
            'guard_name' => Guard::getDefaultName(Role::class),
        ]);

        $superAdmin->forceFill(['data_access_level' => DataAccessLevel::All->value])->save();
        $superAdmin->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $superAdmin;
    }
}
