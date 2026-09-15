<?php

namespace Database\Factories;

use App\Domain\Meta\Enums\MetaAdLevel;
use App\Domain\Meta\Models\MetaInsight;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MetaInsight>
 */
class MetaInsightFactory extends Factory
{
    protected $model = MetaInsight::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $spend = fake()->randomFloat(2, 5, 500);
        $impressions = fake()->numberBetween(500, 50000);
        $clicks = fake()->numberBetween(5, 500);

        return [
            'level' => MetaAdLevel::Campaign->value,
            'entity_id' => (string) fake()->randomNumber(9, true),
            'date' => Carbon::yesterday()->toDateString(),
            'spend' => $spend,
            'impressions' => $impressions,
            'reach' => (int) ($impressions * 0.7),
            'clicks' => $clicks,
            'ctr' => round(($clicks / max(1, $impressions)) * 100, 4),
            'cpc' => round($spend / max(1, $clicks), 4),
            'cpm' => round(($spend / max(1, $impressions)) * 1000, 4),
            'leads' => fake()->numberBetween(0, 40),
            'conversions' => fake()->numberBetween(0, 10),
            'currency' => 'GBP',
            // Figures move for up to 72 hours, so a row always knows when it was
            // read.
            'read_at' => now(),
        ];
    }

    public function atLevel(MetaAdLevel $level, string $entityId): static
    {
        return $this->state(fn () => ['level' => $level->value, 'entity_id' => $entityId]);
    }

    public function on(Carbon|string $date): static
    {
        return $this->state(fn () => [
            'date' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }
}
