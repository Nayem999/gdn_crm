<?php

namespace Database\Factories;

use App\Domain\Meta\Models\MetaAdSet;
use App\Domain\Meta\Models\MetaCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaAdSet>
 */
class MetaAdSetFactory extends Factory
{
    protected $model = MetaAdSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meta_ad_set_id' => (string) fake()->unique()->randomNumber(9, true),
            // Meta's campaign id, not a key of ours. A bare value rather than a
            // factory: an ad set can exist here before its campaign does, which
            // is exactly what the sync has to cope with.
            'meta_campaign_id' => (string) fake()->randomNumber(9, true),
            'name' => ucfirst(fake()->words(2, true)).' audience',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'optimisation_goal' => 'LEAD_GENERATION',
            'billing_event' => 'IMPRESSIONS',
            'daily_budget' => 25.00,
            'last_synced_at' => now(),
        ];
    }

    public function under(MetaCampaign $campaign): static
    {
        return $this->state(fn () => ['meta_campaign_id' => $campaign->meta_campaign_id]);
    }
}
