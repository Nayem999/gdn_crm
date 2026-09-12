<?php

namespace App\Domain\Products\Models;

use Database\Factories\ProductBundleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing inside a bundle, and how many of it.
 *
 * A model rather than a bare pivot because 6.2's line items will explode a
 * bundle into its parts, and that needs something to hold an id and a quantity
 * it can point at.
 *
 * @property int $id
 * @property int $bundle_id
 * @property int $product_id
 * @property string $quantity
 * @property int $position
 */
class ProductBundleItem extends Model
{
    /** @use HasFactory<ProductBundleItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['bundle_id', 'product_id', 'quantity', 'position'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function quantity(): float
    {
        return (float) $this->getAttributeValue('quantity');
    }
}
