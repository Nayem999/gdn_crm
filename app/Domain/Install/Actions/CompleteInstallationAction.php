<?php

namespace App\Domain\Install\Actions;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Install\DTOs\InstallData;
use App\Domain\Install\Installation;
use App\Domain\Settings\Actions\SaveSettingsAction;
use App\Models\User;
use Database\Seeders\BaselineSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Turns a filled-in wizard into a usable installation.
 *
 * Everything it does is inside one transaction, because a half-installed
 * application is worse than an uninstalled one: an administrator with no
 * Super Admin role can log in and do nothing, and cannot be fixed from the UI
 * because fixing it needs the permission they do not have.
 *
 * It re-checks that the installation is pending rather than trusting the caller.
 * The middleware in front of the wizard is the first gate; this is the one that
 * holds when two people open the form at the same time.
 */
class CompleteInstallationAction
{
    public function __construct(private readonly SaveSettingsAction $settings) {}

    public function __invoke(InstallData $data): User
    {
        if (Installation::isComplete()) {
            throw new RuntimeException('This application has already been installed.');
        }

        $user = DB::transaction(function () use ($data): User {
            $this->seedBaseline();

            $company = Company::current();
            $company->fill([
                'name' => $data->companyName,
                'timezone' => $data->timezone,
                'currency' => strtoupper($data->currency),
                'fiscal_year_start_month' => $data->fiscalYearStartMonth,
            ])->save();

            $user = User::create([
                'name' => $data->adminName,
                'email' => $data->adminEmail,
                'password' => Hash::make($data->adminPassword),
            ]);

            // The first administrator has nothing to verify against yet: the
            // mail provider is being configured in the same form. forceFill
            // because the stamp is deliberately not fillable — nothing but this
            // and the verification flow itself may set it.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->syncRoles([$this->superAdminRole()]);

            if ($data->mail !== []) {
                // Through SaveSettingsAction, so the provider's secrets are
                // encrypted at rest and the change is audited by key name — the
                // same path the settings screen uses. Never written here
                // directly, and never to the environment file.
                $this->settings->handle('mail', $data->mail);
            }

            Installation::markComplete();

            return $user;
        });

        return $user;
    }

    /**
     * The permission catalogue, the Super Admin role, a pipeline, the lead
     * scoring rules and the standard reports.
     */
    private function seedBaseline(): void
    {
        Artisan::call('db:seed', [
            '--class' => BaselineSeeder::class,
            '--force' => true,
        ]);
    }

    private function superAdminRole(): Role
    {
        $role = Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN_ROLE)->first();

        if ($role === null) {
            // The baseline seeder creates it. Reaching here means the seed
            // silently did nothing, and carrying on would produce exactly the
            // locked-out administrator the transaction exists to prevent.
            throw new RuntimeException('The '.PermissionCatalogue::SUPER_ADMIN_ROLE.' role was not created.');
        }

        return $role;
    }
}
