<?php

namespace Database\Factories;

use App\Domain\Users\Models\UserInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserInvitation>
 */
class UserInvitationFactory extends Factory
{
    protected $model = UserInvitation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'token' => UserInvitation::hashToken(Str::random(64)),
            'role_id' => null,
            'team_id' => null,
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * An invitation whose plain-text token is known, so tests can visit the link.
     */
    public function withToken(string $plainToken): static
    {
        return $this->state(fn () => ['token' => UserInvitation::hashToken($plainToken)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => now()]);
    }
}
