<?php

namespace Database\Factories;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Meta\Models\MetaCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaCampaign>
 */
class MetaCampaignFactory extends Factory
{
    protected $model = MetaCampaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meta_campaign_id' => (string) fake()->unique()->randomNumber(9, true),
            'ad_account_id' => (string) fake()->randomNumber(9, true),
            'name' => ucfirst(fake()->words(2, true)).' campaign',
            'objective' => 'OUTCOME_LEADS',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'daily_budget' => 50.00,
            'start_time' => now()->subWeeks(2),
            'last_synced_at' => now(),
        ];
    }

    /**
     * Linked to a CRM campaign, which is the state every figure in 12.13
     * depends on.
     */
    public function linkedTo(Campaign $campaign): static
    {
        return $this->state(fn () => [
            'campaign_id' => $campaign->id,
            'linked_at' => now(),
        ]);
    }

    /**
     * Set ACTIVE and spending nothing, because the ad account it sits in is
     * disabled. Meta's two statuses disagreeing is the case worth fixturing.
     */
    public function paused(): static
    {
        return $this->state(fn () => ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    }
}
