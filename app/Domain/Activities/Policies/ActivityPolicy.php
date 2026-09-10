<?php

namespace App\Domain\Activities\Policies;

use App\Domain\Activities\Models\Activity;
use App\Models\User;

/**
 * Both questions must pass: does this person hold the permission, and does the
 * activity fall inside their access level? Visibility uses the same scope the
 * list does, so one that is hidden cannot be reached by guessing its id.
 *
 * An activity's visibility follows its **owner**, not the record it is about.
 * That is the house convention every module uses, and the alternative — asking
 * the related record's policy — would make a person's own task list depend on
 * whether they can still see the account it hangs off.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activities.view');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('activities.view') && $this->isVisibleTo($user, $activity);
    }

    public function create(User $user): bool
    {
        return $user->can('activities.create');
    }

    /**
     * Completing, reopening and cancelling are all part of doing the work, so
     * they sit under update rather than being a permission of their own — a
     * person who may not mark their own call done cannot use the module at all.
     */
    public function update(User $user, Activity $activity): bool
    {
        return $user->can('activities.update') && $this->isVisibleTo($user, $activity);
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $user->can('activities.delete') && $this->isVisibleTo($user, $activity);
    }

    /**
     * Handing work to somebody else is its own permission, the way it is for
     * leads and deals.
     */
    public function assign(User $user, Activity $activity): bool
    {
        return $user->can('activities.assign') && $this->isVisibleTo($user, $activity);
    }

    public function export(User $user): bool
    {
        return $user->can('activities.export');
    }

    private function isVisibleTo(User $user, Activity $activity): bool
    {
        return Activity::query()
            ->visibleTo($user)
            ->whereKey($activity->getKey())
            ->withTrashed()
            ->exists();
    }
}
