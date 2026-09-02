<?php

namespace App\Domain\Audit\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('audit.view');
    }
}
