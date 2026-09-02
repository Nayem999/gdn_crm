<?php

namespace App\Providers;

use App\Domain\Auth\Listeners\RecordLoginHistory;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Policies\CompanyPolicy;
use App\Domain\Teams\Policies\TeamPolicy;
use App\Domain\Users\Policies\UserPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);

        Event::subscribe(RecordLoginHistory::class);

        // Models live under app/Domain/{Module}/Models, which Laravel's default
        // resolver would map to Database\Factories\Domain\{Module}\Models\...
        // All factories live flat in Database\Factories instead.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }
}
