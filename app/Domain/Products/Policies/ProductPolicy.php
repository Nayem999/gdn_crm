<?php

namespace App\Domain\Products\Policies;

use App\Domain\Products\Models\Product;
use App\Models\User;

/**
 * The catalogue follows the same access levels as every other business model:
 * a permission to act at all, and the record's own visibility scope deciding
 * which rows that applies to.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view') && $this->isVisibleTo($user, $product);
    }

    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.update') && $this->isVisibleTo($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.delete') && $this->isVisibleTo($user, $product);
    }

    public function export(User $user): bool
    {
        return $user->can('products.export');
    }

    /**
     * Whether this record falls inside the user's access level.
     */
    private function isVisibleTo(User $user, Product $product): bool
    {
        return Product::query()
            ->visibleTo($user)
            ->whereKey($product->getKey())
            ->withTrashed()
            ->exists();
    }
}
