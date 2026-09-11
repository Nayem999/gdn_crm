<?php

namespace App\Domain\CustomModules\Policies;

use App\Domain\CustomModules\Models\CustomModule;
use App\Models\User;

/**
 * Defining a module is an administrative act, so there is no access level here
 * — only the permission.
 */
class CustomModulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('custom-modules.configure');
    }

    public function view(User $user, CustomModule $module): bool
    {
        return $user->can('custom-modules.configure');
    }

    public function create(User $user): bool
    {
        return $user->can('custom-modules.configure');
    }

    public function update(User $user, CustomModule $module): bool
    {
        return $user->can('custom-modules.configure');
    }

    public function delete(User $user, CustomModule $module): bool
    {
        return $user->can('custom-modules.configure');
    }
}
