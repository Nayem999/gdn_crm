<?php

namespace App\Domain\Products\Models;

use Database\Factories\PriceBreakFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price that starts applying at a quantity.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $price_book_id
 * @property string $min_quantity
 * @property string $price
 */
class PriceBreak extends Model
{
    /** @use HasFactory<PriceBreakFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['product_id', 'price_book_id', 'min_quantity', 'price'];

    /**
     * `price()` is named after its column.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['price' => 0, 'min_quantity' => 1];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<PriceBook, $this>
     */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    public function price(): float
    {
        return (float) $this->getAttributeValue('price');
    }

    public function minQuantity(): float
    {
        return (float) $this->getAttributeValue('min_quantity');
    }
}
