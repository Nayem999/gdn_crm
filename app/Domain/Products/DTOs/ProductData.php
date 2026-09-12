<?php

namespace App\Domain\Products\DTOs;

use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Enums\ProductUnit;

/**
 * A catalogue row as a form submitted it, already normalised.
 *
 * @phpstan-type ComponentShape array{product_id: int, quantity: float, position: int}
 */
readonly class ProductData
{
    /**
     * @param  array<int, ComponentShape>  $components  Only meaningful for a bundle.
     */
    public function __construct(
        public string $name,
        public ProductKind $kind,
        public ProductUnit $unit,
        public ?string $sku = null,
        public ?string $description = null,
        public ?string $category = null,
        public ?float $costPrice = null,
        public float $listPrice = 0.0,
        public ?float $taxRate = null,
        public bool $isActive = true,
        public ?int $ownerId = null,
        public array $components = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $kind = ProductKind::tryFrom((string) ($attributes['kind'] ?? '')) ?? ProductKind::Product;

        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            kind: $kind,
            unit: ProductUnit::tryFrom((string) ($attributes['unit'] ?? '')) ?? ProductUnit::Each,
            // Uppercased and trimmed: a catalogue code is a code, and "ab-1"
            // and "AB-1" being two rows is how a catalogue stops being one.
            sku: self::sku($attributes['sku'] ?? null),
            description: self::text($attributes, 'description'),
            category: self::text($attributes, 'category'),
            // A bundle's cost is the sum of what is in it, so it never carries
            // its own — a second answer beside the first would drift from it.
            costPrice: $kind->hasOwnCost() ? self::money($attributes['cost_price'] ?? null) : null,
            listPrice: self::money($attributes['list_price'] ?? null) ?? 0.0,
            taxRate: self::money($attributes['tax_rate'] ?? null),
            isActive: (bool) ($attributes['is_active'] ?? true),
            ownerId: isset($attributes['owner_id']) && $attributes['owner_id'] !== ''
                ? (int) $attributes['owner_id']
                : null,
            // Only a bundle keeps components, so changing a bundle to a product
            // cannot leave parts behind that nothing reads.
            components: $kind->hasComponents() ? self::components($attributes['components'] ?? []) : [],
        );
    }

    /**
     * The columns this writes on a product row.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'sku' => $this->sku,
            'kind' => $this->kind->value,
            'unit' => $this->unit->value,
            'description' => $this->description,
            'category' => $this->category,
            'cost_price' => $this->costPrice,
            'list_price' => $this->listPrice,
            'tax_rate' => $this->taxRate,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * @return array<int, array{product_id: int, quantity: float, position: int}>
     */
    private static function components(mixed $components): array
    {
        if (! is_array($components)) {
            return [];
        }

        $normalised = [];
        $seen = [];
        $position = 0;

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $productId = (int) ($component['product_id'] ?? 0);
            $quantity = (float) ($component['quantity'] ?? 1);

            // A component listed twice, or with nothing in it, is a row
            // somebody started and abandoned. The unique index would refuse the
            // duplicate anyway; dropping it here means a readable error rather
            // than a database one.
            if ($productId <= 0 || $quantity <= 0 || in_array($productId, $seen, true)) {
                continue;
            }

            $seen[] = $productId;
            $normalised[] = [
                'product_id' => $productId,
                'quantity' => round($quantity, 3),
                'position' => $position,
            ];
            $position++;
        }

        return $normalised;
    }

    private static function sku(mixed $value): ?string
    {
        $sku = strtoupper(trim((string) $value));

        return $sku === '' ? null : $sku;
    }

    private static function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): ?string
    {
        $value = trim((string) ($attributes[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
