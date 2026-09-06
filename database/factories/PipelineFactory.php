<?php

namespace Database\Factories;

use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)).' pipeline',
            'description' => fake()->boolean() ? fake()->sentence() : null,
            'is_default' => false,
            'position' => 0,
        ];
    }

    public function default(): self
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /**
     * A pipeline that is actually usable — one with stages.
     *
     * Off by default: plenty of tests care only about the pipeline row, and
     * writing four stages for each of them would make them slow for nothing.
     */
    public function withStages(int $openStages = 2): self
    {
        return $this->afterCreating(function (Pipeline $pipeline) use ($openStages) {
            $position = 0;

            foreach (range(1, max(1, $openStages)) as $index) {
                PipelineStage::factory()->for($pipeline)->create([
                    'key' => 'stage_'.$index,
                    'name' => 'Stage '.$index,
                    'probability' => min(90, $index * 25),
                    'outcome' => StageOutcome::Open->value,
                    'position' => $position++,
                ]);
            }

            PipelineStage::factory()->for($pipeline)->won()->create(['position' => $position++]);
            PipelineStage::factory()->for($pipeline)->lost()->create(['position' => $position]);
        });
    }
}
