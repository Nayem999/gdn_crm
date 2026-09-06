<?php

namespace App\Domain\Deals\Policies;

use App\Domain\Deals\Models\Deal;
use App\Models\User;

/**
 * Deals arrive with lead conversion, before the module that owns them.
 *
 * Only what 2.6 needs is here: whether somebody may see a deal. Phase 3.2 adds
 * create, update, delete and export alongside the permission group and the
 * screens that use them.
 */
class DealPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('deals.view');
    }

    public function view(User $user, Deal $deal): bool
    {
        return $user->can('deals.view') && $this->isVisibleTo($user, $deal);
    }

    private function isVisibleTo(User $user, Deal $deal): bool
    {
        return Deal::query()
            ->visibleTo($user)
            ->whereKey($deal->getKey())
            ->withTrashed()
            ->exists();
    }
}
