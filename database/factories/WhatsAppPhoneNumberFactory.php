<?php

namespace Database\Factories;

use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppPhoneNumber>
 */
class WhatsAppPhoneNumberFactory extends Factory
{
    protected $model = WhatsAppPhoneNumber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_business_account_id' => WhatsAppBusinessAccount::factory(),
            'phone_number_id' => (string) fake()->unique()->randomNumber(9, true),
            'display_number' => '+880 1'.fake()->numerify('### ######'),
            'verified_name' => fake()->company(),
            'quality_rating' => 'GREEN',
            'messaging_limit' => 'TIER_1K',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /**
     * A number Meta has flagged, which changes what it may send today.
     */
    public function flagged(string $rating = 'RED'): static
    {
        return $this->state(fn () => ['quality_rating' => $rating]);
    }
}
