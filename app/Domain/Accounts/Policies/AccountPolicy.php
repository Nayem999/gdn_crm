<?php

namespace App\Domain\Accounts\Policies;

use App\Domain\Accounts\Models\Account;
use App\Models\User;

/**
 * Two questions are answered separately and both must pass: does this person
 * hold the permission at all, and can they see this particular record?
 *
 * Visibility comes from the same access level ScopesByAccessLevel applies to
 * lists, so a record hidden from a list cannot be reached by guessing its id.
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounts.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can('accounts.view') && $this->isVisibleTo($user, $account);
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.create');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounts.update') && $this->isVisibleTo($user, $account);
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounts.delete') && $this->isVisibleTo($user, $account);
    }

    public function export(User $user): bool
    {
        return $user->can('accounts.export');
    }

    /**
     * Whether this record falls inside the user's access level.
     */
    /**
     * Merging is its own permission: it folds one record into another and
     * takes one of them off the list, which is more than an edit.
     */
    public function merge(User $user, Account $account): bool
    {
        return $user->can('accounts.merge') && $this->isVisibleTo($user, $account);
    }

    private function isVisibleTo(User $user, Account $account): bool
    {
        return Account::query()
            ->visibleTo($user)
            ->whereKey($account->getKey())
            ->withTrashed()
            ->exists();
    }
}
