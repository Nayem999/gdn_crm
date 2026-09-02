<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Shared\Enums\DataAccessLevel;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Sync the permission catalogue into the database and keep the Super Admin
     * role holding all of it. Safe to re-run: it is how newly added module
     * permissions reach the protected role.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(PermissionCatalogue::all())
            ->map(fn (string $name) => Permission::findOrCreate($name));

        $superAdmin = Role::findOrCreate(PermissionCatalogue::SUPER_ADMIN_ROLE);
        $superAdmin->forceFill(['data_access_level' => DataAccessLevel::All->value])->save();
        $superAdmin->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
