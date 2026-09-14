<?php

namespace Database\Factories;

use App\Domain\Attribution\Models\RecordAttribution;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<RecordAttribution>
 */
class RecordAttributionFactory extends Factory
{
    protected $model = RecordAttribution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attributable_type' => (new Lead)->getMorphClass(),
            'attributable_id' => Lead::factory(),
            'source' => LeadSource::FacebookLeadAds->value,
            'source_detail' => 'Facebook Lead Ads',
            'captured_at' => now(),
        ];
    }

    public function for_(Model $record): static
    {
        return $this->state(fn () => [
            'attributable_type' => $record->getMorphClass(),
            'attributable_id' => $record->getKey(),
        ]);
    }

    /**
     * A full Meta ad hierarchy, the way a lead-ads submission arrives.
     */
    public function fromMeta(): static
    {
        return $this->state(fn () => [
            'source' => LeadSource::FacebookLeadAds->value,
            'meta_lead_id' => (string) fake()->unique()->randomNumber(9, true),
            'page_id' => '1019283746',
            'form_id' => '5566778899',
            'form_name' => 'Pump enquiry',
            'meta_campaign_id' => '23851234567890',
            'meta_campaign_name' => 'Summer pump campaign',
            'meta_ad_set_id' => '23851234567891',
            'meta_ad_set_name' => 'Dhaka dealers',
            'meta_ad_id' => '23851234567892',
            'meta_ad_name' => 'Pump offer video',
            'click_id' => 'IwAR'.fake()->lexify('??????????'),
        ]);
    }

    public function withUtm(): static
    {
        return $this->state(fn () => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'summer-pump',
            'utm_content' => 'video-a',
        ]);
    }
}
