<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            // Unique because it is how a public request finds its workspace,
            // and a duplicate would send one customer's form to another.
            'slug' => str()->slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ];
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
