<?php

namespace Database\Factories;

use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppBusinessAccount>
 */
class WhatsAppBusinessAccountFactory extends Factory
{
    protected $model = WhatsAppBusinessAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meta_account_id' => MetaAccount::factory(),
            'waba_id' => (string) fake()->unique()->randomNumber(9, true),
            'name' => fake()->company().' WhatsApp',
            'timezone' => 'Asia/Dhaka',
            'message_template_namespace' => fake()->uuid(),
            // Its own token: a WhatsApp business account is not a page, and the
            // two are frequently owned by different businesses.
            'access_token' => 'EAAWaba'.fake()->lexify('????????????'),
            'is_subscribed' => true,
            'last_synced_at' => now(),
        ];
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => ['is_subscribed' => false]);
    }
}
