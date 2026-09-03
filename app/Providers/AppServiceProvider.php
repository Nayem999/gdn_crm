<?php

namespace App\Providers;

use App\Domain\Access\Policies\RolePolicy;
use App\Domain\Audit\Policies\ActivityPolicy;
use App\Domain\Auth\Listeners\RecordLoginHistory;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Policies\CompanyPolicy;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Policies\SettingPolicy;
use App\Domain\Settings\SettingsManager;
use App\Domain\Teams\Policies\TeamPolicy;
use App\Domain\Users\Policies\UserPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One manager per request, so its per-request cache of loaded groups is
        // shared by everything that reads a setting during that request.
        $this->app->singleton(SettingsManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        // Super Admin gets its way by holding every permission (kept in sync by
        // RolesAndPermissionsSeeder) rather than a Gate::before bypass, so
        // business rules such as "you cannot delete your own account" still hold.
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);

        Event::subscribe(RecordLoginHistory::class);

        // Models live under app/Domain/{Module}/Models, which Laravel's default
        // resolver would map to Database\Factories\Domain\{Module}\Models\...
        // All factories live flat in Database\Factories instead.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }
}
