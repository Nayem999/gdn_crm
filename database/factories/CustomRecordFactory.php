<?php

namespace Database\Factories;

use App\Domain\CustomModules\Models\CustomModule;
use App\Domain\CustomModules\Models\CustomRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomRecord>
 */
class CustomRecordFactory extends Factory
{
    protected $model = CustomRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'custom_module_id' => CustomModule::factory(),
            'name' => ucfirst(fake()->words(2, true)),
            'owner_id' => User::factory(),
        ];
    }

    public function inModule(CustomModule $module): static
    {
        return $this->state(fn () => ['custom_module_id' => $module->id]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }
}
