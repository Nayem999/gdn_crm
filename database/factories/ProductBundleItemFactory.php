<?php

namespace Database\Factories;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductBundleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBundleItem>
 */
class ProductBundleItemFactory extends Factory
{
    protected $model = ProductBundleItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bundle_id' => Product::factory()->bundle(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'position' => 0,
        ];
    }

    public function of(Product $bundle, Product $product, float $quantity = 1): static
    {
        return $this->state(fn () => [
            'bundle_id' => $bundle->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
    }
}
