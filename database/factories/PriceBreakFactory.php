<?php

namespace Database\Factories;

use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\PriceBreak;
use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceBreak>
 */
class PriceBreakFactory extends Factory
{
    protected $model = PriceBreak::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'price_book_id' => null,
            'min_quantity' => 10,
            'price' => 90,
        ];
    }

    public function at(float $minQuantity, float $price): static
    {
        return $this->state(fn () => ['min_quantity' => $minQuantity, 'price' => $price]);
    }

    public function forProduct(Product $product, ?PriceBook $book = null): static
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'price_book_id' => $book?->id,
        ]);
    }
}
