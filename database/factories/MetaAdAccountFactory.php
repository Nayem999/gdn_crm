<?php

namespace Database\Factories;

use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAdAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaAdAccount>
 */
class MetaAdAccountFactory extends Factory
{
    protected $model = MetaAdAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meta_account_id' => MetaAccount::factory(),
            // Stored bare; MetaAdAccount::graphId() adds the act_ prefix Meta's
            // endpoints want.
            'ad_account_id' => (string) fake()->unique()->randomNumber(9, true),
            'name' => fake()->company().' Ads',
            'currency' => 'USD',
            'timezone' => 'Asia/Dhaka',
            'status' => '1',
            'is_active' => true,
            'last_synced_at' => now(),
        ];
    }

    public function currency(string $currency): static
    {
        return $this->state(fn () => ['currency' => $currency]);
    }

    public function disabled(): static
    {
        // Meta's own code for a disabled account. Its spend is still worth
        // reading, which is why this is a status rather than a deletion.
        return $this->state(fn () => ['status' => '2', 'is_active' => false]);
    }
}
