<?php

namespace App\Domain\Sales\Models;

use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Products\Models\Product;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Pricing\LineCalculator;
use App\Domain\Sales\Pricing\LineTotal;
use Database\Factories\DocumentLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a quote, order or invoice.
 *
 * A **snapshot**: the name, description, unit, price and tax rate are copied
 * onto the line, not read through to the product. A quote sent in March still
 * says what it said in March after the catalogue price goes up in April, which
 * matters because the customer has a copy of the March one.
 *
 * @property int $id
 * @property string $document_type
 * @property int $document_id
 * @property int|null $product_id
 * @property int $position
 * @property string $name
 * @property string|null $description
 * @property string $unit
 * @property string $quantity
 * @property string $unit_price
 * @property string|null $discount_type
 * @property string $discount_value
 * @property string $tax_rate
 * @property string $net_total
 * @property string $tax_total
 * @property string $line_total
 */
class DocumentLine extends Model
{
    /** @use HasFactory<DocumentLineFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_type',
        'document_id',
        'product_id',
        'position',
        'name',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'tax_rate',
        'net_total',
        'tax_total',
        'line_total',
    ];

    /**
     * `unit()` and `quantity()` are named after their columns, so both need a
     * default. `discount_type` is nullable and its accessor returns null, so it
     * needs one too — the key has to be present, not non-null.
     * See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'unit' => ProductUnit::Each->value,
        'quantity' => 1,
        'unit_price' => 0,
        'discount_type' => null,
        'discount_value' => 0,
        'tax_rate' => 0,
        'net_total' => 0,
        'tax_total' => 0,
        'line_total' => 0,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'net_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * The quote, order or invoice this is a line of.
     *
     * @return MorphTo<Model, $this>
     */
    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * What was sold, when it is still in the catalogue.
     *
     * Null is an ordinary state, not a fault: the line carries everything it
     * needs to print.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quantity(): float
    {
        return (float) $this->getAttributeValue('quantity');
    }

    public function unit(): ProductUnit
    {
        return ProductUnit::tryFrom((string) $this->getAttributeValue('unit')) ?? ProductUnit::Each;
    }

    public function discountType(): ?DiscountType
    {
        return DiscountType::tryFrom((string) $this->getAttributeValue('discount_type'));
    }

    /**
     * How the discount reads on a printed line: "10%" or "25.00".
     */
    public function discountLabel(): ?string
    {
        $type = $this->discountType();
        $value = (float) $this->discount_value;

        if ($type === null || $value <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.').$type->suffix();
    }

    /**
     * What this line comes to, recomputed.
     *
     * The stored figures are what the document shows; this is what they should
     * be. They differ only if something wrote a line without going through
     * `LineCalculator`, which is what the guard test checks for.
     */
    public function recalculate(TaxMode $taxMode = TaxMode::Exclusive): LineTotal
    {
        return app(LineCalculator::class)->forLine($this, $taxMode);
    }

    /**
     * How it reads beside the quantity: "3 hours", "1 licence".
     */
    public function quantityLabel(): string
    {
        $quantity = $this->quantity();
        $formatted = rtrim(rtrim(number_format($quantity, 3, '.', ','), '0'), '.');

        return $formatted.' '.$this->unit()->forQuantity($quantity);
    }
}
