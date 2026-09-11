<?php

namespace App\Domain\CustomModules\Policies;

use App\Domain\CustomModules\Models\CustomRecord;
use App\Models\User;

/**
 * Records in a generated module.
 *
 * **One permission set covers every generated module**, rather than a set per
 * module. `PermissionCatalogue` is a static list that the roles matrix screen
 * renders, and permissions invented at runtime would have to be discovered and
 * synced on every module save — which is a Phase 1.5 change to how the
 * catalogue works, not something to bolt on here. Stated so nobody assumes a
 * finer grain exists: somebody who may see one generated module may see them
 * all, subject to their data access level on the records themselves.
 */
class CustomRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('custom-modules.view');
    }

    public function view(User $user, CustomRecord $record): bool
    {
        return $user->can('custom-modules.view') && $this->isVisibleTo($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->can('custom-modules.create');
    }

    public function update(User $user, CustomRecord $record): bool
    {
        return $user->can('custom-modules.update') && $this->isVisibleTo($user, $record);
    }

    public function delete(User $user, CustomRecord $record): bool
    {
        return $user->can('custom-modules.delete') && $this->isVisibleTo($user, $record);
    }

    public function export(User $user): bool
    {
        return $user->can('custom-modules.export');
    }

    private function isVisibleTo(User $user, CustomRecord $record): bool
    {
        return CustomRecord::query()
            ->visibleTo($user)
            ->whereKey($record->getKey())
            ->withTrashed()
            ->exists();
    }
}
