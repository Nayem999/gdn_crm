<?php

namespace Database\Factories;

use App\Domain\Meta\Enums\MetaLeadStatus;
use App\Domain\Meta\Models\MetaForm;
use App\Domain\Meta\Models\MetaLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaLead>
 */
class MetaLeadFactory extends Factory
{
    protected $model = MetaLead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meta_lead_id' => (string) fake()->unique()->randomNumber(9, true),
            'meta_form_id' => MetaForm::factory(),
            'form_id' => (string) fake()->randomNumber(9, true),
            'page_id' => (string) fake()->randomNumber(9, true),
            'meta_campaign_id' => (string) fake()->randomNumber(9, true),
            'meta_campaign_name' => 'Spring campaign',
            'meta_ad_set_id' => (string) fake()->randomNumber(9, true),
            'meta_ad_set_name' => 'Prospecting',
            'meta_ad_id' => (string) fake()->randomNumber(9, true),
            'meta_ad_name' => 'Carousel A',
            'received_at' => now(),
        ];
    }

    /**
     * Arrived and not yet dealt with, which is every row for the moment between
     * the webhook answering and the queue picking it up.
     */
    public function received(): static
    {
        return $this->state(fn () => [
            'status' => MetaLeadStatus::Received->value,
            'processed_at' => null,
        ]);
    }

    public function failed(string $error = 'Meta was unreachable.'): static
    {
        return $this->state(fn () => [
            'status' => MetaLeadStatus::Failed->value,
            'error' => $error,
            'processed_at' => now(),
        ]);
    }
}
