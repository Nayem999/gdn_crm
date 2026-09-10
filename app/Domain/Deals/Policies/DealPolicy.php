<?php

namespace App\Domain\Deals\Policies;

use App\Domain\Deals\Models\Deal;
use App\Models\User;

/**
 * Both questions must pass: does this person hold the permission, and does the
 * record fall inside their access level? Visibility uses the same scope the
 * lists do, so a hidden deal cannot be reached by guessing its id.
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

    public function create(User $user): bool
    {
        return $user->can('deals.create');
    }

    public function update(User $user, Deal $deal): bool
    {
        return $user->can('deals.update') && $this->isVisibleTo($user, $deal);
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $user->can('deals.delete') && $this->isVisibleTo($user, $deal);
    }

    /**
     * Handing a deal to somebody else is its own permission: moving work
     * between people is a different act from editing its details.
     */
    public function assign(User $user, Deal $deal): bool
    {
        return $user->can('deals.assign') && $this->isVisibleTo($user, $deal);
    }

    /**
     * Closing is its own permission. Won and lost figures are what the business
     * is measured on, so declaring one is more than an edit.
     */
    public function close(User $user, Deal $deal): bool
    {
        return $user->can('deals.close') && $this->isVisibleTo($user, $deal);
    }

    public function export(User $user): bool
    {
        return $user->can('deals.export');
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
