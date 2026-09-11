<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Domain\Dashboard\DashboardScope;
use App\Models\User;

/**
 * The signed-in person, typed, and their dashboard scope.
 *
 * Every widget needs both and none of them should be reaching for
 * `auth()->user()` and hoping: the scope is what decides which figures a widget
 * is allowed to show, so it is built one way in one place.
 */
trait ScopesToViewer
{
    protected function viewer(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    protected function scope(): DashboardScope
    {
        return new DashboardScope($this->viewer());
    }
}
