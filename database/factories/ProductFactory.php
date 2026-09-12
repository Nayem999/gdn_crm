<?php

namespace Database\Factories;

use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            // Unique, because the column is: a factory producing collisions
            // would make every test that creates two products flaky.
            'sku' => strtoupper(fake()->unique()->bothify('??-####')),
            'kind' => ProductKind::Product->value,
            'unit' => ProductUnit::Each->value,
            'description' => null,
            'category' => null,
            'cost_price' => 60,
            'list_price' => 100,
            'tax_rate' => null,
            'is_active' => true,
            'owner_id' => User::factory(),
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'kind' => ProductKind::Service->value,
            'unit' => ProductUnit::Hour->value,
            'cost_price' => null,
        ]);
    }

    public function bundle(): static
    {
        return $this->state(fn () => [
            'kind' => ProductKind::Bundle->value,
            // A bundle's cost is the sum of what is in it.
            'cost_price' => null,
        ]);
    }

    public function pricedAt(float $listPrice, ?float $costPrice = null): static
    {
        return $this->state(fn () => ['list_price' => $listPrice, 'cost_price' => $costPrice]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function inCategory(string $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }
}
