<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\Models\Product;
use RuntimeException;

/**
 * Removes a catalogue row.
 *
 * Refused while the product is inside a bundle. The foreign key would refuse it
 * anyway — `product_bundle_items.product_id` restricts on delete — but a
 * database error is not something a screen can show somebody, and "deactivate
 * it instead" is the answer they actually want: a product that is no longer
 * sold on its own is usually still part of something that is.
 */
class DeleteProductAction
{
    /**
     * @throws RuntimeException when the product is part of a bundle
     */
    public function __invoke(Product $product): void
    {
        $bundles = $product->partOfBundles()->pluck('name');

        if ($bundles->isNotEmpty()) {
            throw new RuntimeException(
                $product->name.' is part of '.$bundles->join(', ', ' and ')
                .'. Switch it off instead, or take it out of the bundle first.'
            );
        }

        $product->delete();
    }
}
