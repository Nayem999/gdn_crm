<?php

namespace Database\Factories;

use App\Domain\Meta\Models\MetaAd;
use App\Domain\Meta\Models\MetaAdSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaAd>
 */
class MetaAdFactory extends Factory
{
    protected $model = MetaAd::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meta_ad_id' => (string) fake()->unique()->randomNumber(9, true),
            'meta_ad_set_id' => (string) fake()->randomNumber(9, true),
            'meta_campaign_id' => (string) fake()->randomNumber(9, true),
            'name' => 'Creative '.strtoupper(fake()->randomLetter()),
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'creative_name' => 'Spring carousel',
            'creative_summary' => fake()->sentence(8),
            'last_synced_at' => now(),
        ];
    }

    public function under(MetaAdSet $adSet): static
    {
        return $this->state(fn () => [
            'meta_ad_set_id' => $adSet->meta_ad_set_id,
            'meta_campaign_id' => $adSet->meta_campaign_id,
        ]);
    }
}
