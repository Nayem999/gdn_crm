<?php

namespace App\Domain\Leads\Policies;

use App\Domain\Leads\Models\Lead;
use App\Models\User;

/**
 * Both questions must pass: does this person hold the permission, and does the
 * record fall inside their access level? Visibility uses the same scope the
 * lists do, so a hidden lead cannot be reached by guessing its id.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('leads.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can('leads.view') && $this->isVisibleTo($user, $lead);
    }

    public function create(User $user): bool
    {
        return $user->can('leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can('leads.update') && $this->isVisibleTo($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can('leads.delete') && $this->isVisibleTo($user, $lead);
    }

    /**
     * Reassigning a lead to someone else is its own permission: moving work
     * between people is a different act from editing its details.
     */
    public function assign(User $user, Lead $lead): bool
    {
        return $user->can('leads.assign') && $this->isVisibleTo($user, $lead);
    }

    public function export(User $user): bool
    {
        return $user->can('leads.export');
    }

    private function isVisibleTo(User $user, Lead $lead): bool
    {
        return Lead::query()
            ->visibleTo($user)
            ->whereKey($lead->getKey())
            ->withTrashed()
            ->exists();
    }
}
