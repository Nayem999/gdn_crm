<?php

namespace Database\Factories;

use App\Domain\Auth\Enums\LoginEvent;
use App\Domain\Auth\Models\LoginHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginHistory>
 */
class LoginHistoryFactory extends Factory
{
    protected $model = LoginHistory::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->safeEmail(),
            'event' => LoginEvent::Login,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['event' => LoginEvent::Failed, 'user_id' => null]);
    }
}
