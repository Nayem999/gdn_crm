<?php

namespace Database\Factories;

use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealStageEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<DealStageEntry>
 */
class DealStageEntryFactory extends Factory
{
    protected $model = DealStageEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'pipeline_id' => null,
            'stage_key' => 'scoping',
            'stage_name' => 'Scoping',
            'outcome' => StageOutcome::Open->value,
            'entered_at' => now(),
            'left_at' => null,
            'duration_seconds' => null,
            'moved_by_id' => null,
        ];
    }

    /**
     * A visit that has ended, with the duration the two stamps imply.
     *
     * Derived rather than passed in, so a fixture cannot describe a visit whose
     * stored duration disagrees with its own timestamps.
     */
    public function lasting(Carbon $from, Carbon $to): self
    {
        return $this->state(fn () => [
            'entered_at' => $from,
            'left_at' => $to,
            'duration_seconds' => (int) $from->diffInSeconds($to),
        ]);
    }

    public function atStage(string $key, string $name, StageOutcome $outcome = StageOutcome::Open): self
    {
        return $this->state(fn () => [
            'stage_key' => $key,
            'stage_name' => $name,
            'outcome' => $outcome->value,
        ]);
    }
}
