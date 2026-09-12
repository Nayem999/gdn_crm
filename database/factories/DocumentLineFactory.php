<?php

namespace Database\Factories;

use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Products\Models\Product;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Models\DocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<DocumentLine>
 */
class DocumentLineFactory extends Factory
{
    protected $model = DocumentLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type' => null,
            'document_id' => null,
            'product_id' => null,
            'position' => 0,
            'name' => 'A line',
            'description' => null,
            'unit' => ProductUnit::Each->value,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_type' => null,
            'discount_value' => 0,
            'tax_rate' => 0,
            // Left at zero on purpose: a line written straight through the
            // factory has not been through LineCalculator, and a test about
            // totals should say so rather than inherit figures nothing
            // computed.
            'net_total' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ];
    }

    public function on(Model $document): static
    {
        return $this->state(fn () => [
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'name' => $product->name,
            'unit' => $product->unit()->value,
            'unit_price' => $product->listPrice(),
        ]);
    }

    public function of(float $quantity, float $unitPrice): static
    {
        return $this->state(fn () => ['quantity' => $quantity, 'unit_price' => $unitPrice]);
    }

    public function discounted(DiscountType $type, float $value): static
    {
        return $this->state(fn () => ['discount_type' => $type->value, 'discount_value' => $value]);
    }

    public function taxedAt(float $rate): static
    {
        return $this->state(fn () => ['tax_rate' => $rate]);
    }
}
