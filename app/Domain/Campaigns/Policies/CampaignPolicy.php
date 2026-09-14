<?php

namespace App\Domain\Campaigns\Policies;

use App\Domain\Campaigns\Models\Campaign;
use App\Models\User;

/**
 * Campaigns follow the same shape as every other business model: a permission
 * to act at all, and the record's own visibility scope deciding which rows that
 * applies to.
 */
class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('campaigns.view');
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $user->can('campaigns.view') && $this->isVisibleTo($user, $campaign);
    }

    public function create(User $user): bool
    {
        return $user->can('campaigns.create');
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $user->can('campaigns.update') && $this->isVisibleTo($user, $campaign);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->can('campaigns.delete') && $this->isVisibleTo($user, $campaign);
    }

    public function export(User $user): bool
    {
        return $user->can('campaigns.export');
    }

    /**
     * Whether this record falls inside the user's access level.
     */
    private function isVisibleTo(User $user, Campaign $campaign): bool
    {
        return Campaign::query()
            ->visibleTo($user)
            ->whereKey($campaign->getKey())
            ->withTrashed()
            ->exists();
    }
}
