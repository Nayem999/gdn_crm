<?php

namespace Database\Factories;

use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\PriceBookEntry;
use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceBookEntry>
 */
class PriceBookEntryFactory extends Factory
{
    protected $model = PriceBookEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_book_id' => PriceBook::factory(),
            'product_id' => Product::factory(),
            'price' => 90,
        ];
    }

    public function for_(PriceBook $book, Product $product, float $price): static
    {
        return $this->state(fn () => [
            'price_book_id' => $book->id,
            'product_id' => $product->id,
            'price' => $price,
        ]);
    }
}
