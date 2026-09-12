<?php

namespace App\Domain\Products\Policies;

use App\Domain\Products\Models\PriceBook;
use App\Models\User;

/**
 * Deciding what the company charges is administration, not catalogue work, so
 * it stands apart from products.update the way deals.pipelines stands apart
 * from deals.update. Somebody who maintains product descriptions should not
 * thereby be able to give every customer a different price.
 *
 * There is no access level here: a price book applies to everybody.
 */
class PriceBookPolicy
{
    public function viewAny(User $user): bool
    {
        // Anybody who can see the catalogue can see what it costs; quoting
        // needs it, and hiding prices from the people who quote is not a
        // security boundary, it is an obstacle.
        return $user->can('products.view');
    }

    public function view(User $user, PriceBook $book): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('products.pricing');
    }

    public function update(User $user, PriceBook $book): bool
    {
        return $user->can('products.pricing');
    }

    public function delete(User $user, PriceBook $book): bool
    {
        return $user->can('products.pricing');
    }
}
