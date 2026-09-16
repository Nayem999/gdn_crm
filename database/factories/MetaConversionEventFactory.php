<?php

namespace Database\Factories;

use App\Domain\Meta\Conversions\Enums\ConversionOutcome;
use App\Domain\Meta\Conversions\Enums\ConversionStatus;
use App\Domain\Meta\Models\MetaConversionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MetaConversionEvent>
 */
class MetaConversionEventFactory extends Factory
{
    protected $model = MetaConversionEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Str::random(48),
            'event_name' => ConversionOutcome::Won->eventName(),
            'dataset_id' => '998877665544',
            'action_source' => 'system_generated',
            'value' => 1000,
            'currency' => 'BDT',
            'payload' => ['event_name' => 'Purchase'],
            'status' => ConversionStatus::Pending->value,
            'occurred_at' => now(),
        ];
    }

    public function failed(string $error = 'Meta refused it.'): self
    {
        return $this->state(fn (): array => [
            'status' => ConversionStatus::Failed->value,
            'error' => $error,
            'attempts' => 1,
        ]);
    }

    public function sent(): self
    {
        return $this->state(fn (): array => [
            'status' => ConversionStatus::Sent->value,
            'sent_at' => now(),
            'attempts' => 1,
        ]);
    }
}
