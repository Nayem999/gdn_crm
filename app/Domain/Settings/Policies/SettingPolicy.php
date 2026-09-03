<?php

namespace App\Domain\Settings\Policies;

use App\Domain\Settings\Models\Setting;
use App\Models\User;

/**
 * Secrets sit behind their own permission, separate from ordinary settings, so
 * someone can be trusted to set a date format without being handed the keys to
 * the file store.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.view');
    }

    public function view(User $user, Setting $setting): bool
    {
        return $setting->is_secret
            ? $this->manageSecrets($user)
            : $user->can('settings.view');
    }

    public function update(User $user, Setting $setting): bool
    {
        return $setting->is_secret
            ? $this->manageSecrets($user)
            : $user->can('settings.update');
    }

    public function updateAny(User $user): bool
    {
        return $user->can('settings.update');
    }

    public function manageSecrets(User $user): bool
    {
        return $user->can('settings.secrets');
    }
}
