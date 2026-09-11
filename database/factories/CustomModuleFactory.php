<?php

namespace Database\Factories;

use App\Domain\CustomModules\Models\CustomModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomModule>
 */
class CustomModuleFactory extends Factory
{
    protected $model = CustomModule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'key' => CustomModule::keyFrom($name),
            'name' => $name,
            'plural_name' => str($name)->plural()->toString(),
            'title_label' => 'Name',
            'icon' => 'box',
            'color' => 'slate',
            'description' => null,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn () => [
            'key' => CustomModule::keyFrom($name),
            'name' => $name,
            'plural_name' => str($name)->plural()->toString(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
