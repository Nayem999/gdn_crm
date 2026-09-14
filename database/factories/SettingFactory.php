<?php

namespace Database\Factories;

use App\Domain\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group' => 'company',
            'key' => 'company.'.fake()->unique()->word(),
            'value' => fake()->word(),
            'type' => 'string',
            'is_secret' => false,
        ];
    }

    /**
     * A secret, which the manager encrypts on the way in.
     *
     * Prefer saving through SettingsManager in a test that cares about the
     * encryption: writing the row directly stores the plain value and proves
     * nothing about how the application handles one.
     */
    public function secret(): static
    {
        return $this->state(fn () => ['is_secret' => true]);
    }

    public function in(string $group, string $key): static
    {
        return $this->state(fn () => ['group' => $group, 'key' => $key]);
    }
}
