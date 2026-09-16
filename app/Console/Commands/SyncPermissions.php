<?php

namespace App\Console\Commands;

use App\Domain\Access\Actions\SyncPermissionCatalogueAction;
use App\Domain\Access\PermissionCatalogue;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;

/**
 * Bring an existing installation's permissions up to date with the code.
 *
 * The catalogue is a constant in the application; the rows are in the database.
 * Every phase that adds a module adds permissions to the first and **nothing
 * writes them to the second** except the seeder — so an installation upgraded
 * rather than freshly installed silently lacks them, and the symptom is not an
 * error. It is a screen that renders correctly with a section missing, because
 * a sidebar hides what its viewer may not see.
 *
 * That is how Phase 12 arrived on this installation with the inbox, the ads and
 * the campaigns invisible to an administrator holding the Super Admin role.
 *
 * Idempotent, so it belongs in a deployment script beside `migrate`.
 */
class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Write any newly added permissions into the database and give them to Super Admin';

    public function handle(SyncPermissionCatalogueAction $sync): int
    {
        $before = Permission::query()->count();

        $role = $sync->execute();

        $after = Permission::query()->count();
        $added = $after - $before;

        $this->components->info(sprintf(
            '%d permission%s in the catalogue, %s.',
            count(PermissionCatalogue::all()),
            count(PermissionCatalogue::all()) === 1 ? '' : 's',
            $added === 0 ? 'nothing new to add' : $added.' newly written',
        ));

        $this->components->info(sprintf(
            '"%s" now holds %d of them.',
            $role->name,
            $role->permissions()->count(),
        ));

        // Named rather than left implied: the people who need the new
        // permissions are usually not the one running this, and every other
        // role is somebody's deliberate choice that must not be widened by a
        // deployment step.
        $this->components->warn('Other roles are left exactly as they were. Grant new permissions to them under Settings → Roles.');

        return self::SUCCESS;
    }
}
