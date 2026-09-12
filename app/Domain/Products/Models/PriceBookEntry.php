<?php

namespace App\Domain\Products\Models;

use Database\Factories\PriceBookEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's price in one book.
 *
 * No `is_active`: a price that should not apply is removed from the book. A
 * disabled row would be a price that exists and does not count, which is a
 * state somebody reading the book cannot see the point of.
 *
 * @property int $id
 * @property int $price_book_id
 * @property int $product_id
 * @property string $price
 */
class PriceBookEntry extends Model
{
    /** @use HasFactory<PriceBookEntryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['price_book_id', 'product_id', 'price'];

    /**
     * `price()` is named after its column, so it needs a default or a
     * partially-created instance reads the method and Laravel takes it for a
     * relation. See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['price' => 0];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<PriceBook, $this>
     */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function price(): float
    {
        return (float) $this->getAttributeValue('price');
    }
}
