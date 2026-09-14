<?php

namespace Database\Factories;

use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Enums\MetaConnectionStatus;
use App\Domain\Meta\Models\MetaAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaAccount>
 */
class MetaAccountFactory extends Factory
{
    protected $model = MetaAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => (string) fake()->unique()->randomNumber(9, true),
            'name' => fake()->company().' Business',
            'user_token' => 'EAA'.fake()->lexify('????????????????'),
            'token_expires_at' => now()->addDays(60),
            // Fully granted by default: a fixture missing a permission would
            // make every test that did not care about scopes fail for a reason
            // it was not testing.
            'granted_scopes' => MetaAuthService::SCOPES,
            'status' => MetaConnectionStatus::Connected->value,
            'connected_by_id' => User::factory(),
            'connected_at' => now(),
        ];
    }

    public function connectedBy(User $user): static
    {
        return $this->state(fn () => ['connected_by_id' => $user->id]);
    }

    /**
     * A connection Meta has stopped accepting — still ours, still holding its
     * pages and its history, and unusable until somebody reconnects.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'token_expires_at' => now()->subDay(),
            'status' => MetaConnectionStatus::NeedsReauthorisation->value,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn () => [
            'user_token' => null,
            'token_expires_at' => null,
            'granted_scopes' => null,
            'status' => MetaConnectionStatus::Disconnected->value,
        ]);
    }

    /**
     * Authorised, but with permissions declined on the consent screen.
     *
     * @param  array<int, string>  $scopes
     */
    public function granted(array $scopes): static
    {
        return $this->state(fn () => ['granted_scopes' => $scopes]);
    }
}
