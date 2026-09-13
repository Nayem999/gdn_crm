<?php

namespace App\Domain\Knowledge\Policies;

use App\Domain\Knowledge\Models\Category;
use App\Models\User;

/**
 * Arranging the library is an editorial act, so it rides on the permission to
 * write articles rather than having one of its own — a desk that lets somebody
 * write does not then stop them filing what they wrote.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('knowledge.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->can('knowledge.view');
    }

    public function create(User $user): bool
    {
        return $user->can('knowledge.update');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->can('knowledge.update');
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->can('knowledge.delete');
    }
}
