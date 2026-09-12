<?php

namespace Database\Factories;

use App\Domain\Products\Models\PriceBook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PriceBook>
 */
class PriceBookFactory extends Factory
{
    protected $model = PriceBook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' prices',
            'description' => null,
            // Not the default: a factory that made every book the default
            // would have the last one created silently win.
            'is_default' => false,
            'is_active' => true,
            'valid_from' => null,
            'valid_to' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function validBetween(?Carbon $from, ?Carbon $to): static
    {
        return $this->state(fn () => ['valid_from' => $from, 'valid_to' => $to]);
    }
}
