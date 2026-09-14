<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // The stock stub is an empty allowlist, which closes Horizon to
        // everybody — including the administrator who needs it — and looks
        // like a permission fault rather than a configuration one. Gated on a
        // real permission instead, so it follows the same roles screen as the
        // rest of the application.
        Gate::define('viewHorizon', function ($user = null): bool {
            return $user !== null && $user->can('settings.view');
        });
    }
}
