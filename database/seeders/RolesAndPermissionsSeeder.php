<?php

namespace Database\Seeders;

use App\Domain\Access\Actions\SyncPermissionCatalogueAction;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Sync the permission catalogue into the database and keep the Super Admin
     * role holding all of it. Safe to re-run: it is how newly added module
     * permissions reach the protected role.
     */
    public function run(): void
    {
        app(SyncPermissionCatalogueAction::class)->execute();
    }
}
