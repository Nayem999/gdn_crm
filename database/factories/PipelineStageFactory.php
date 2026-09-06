<?php

namespace Database\Factories;

use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'pipeline_id' => Pipeline::factory(),
            'key' => PipelineStage::keyFrom($name.'_'.fake()->unique()->randomNumber(4)),
            'name' => $name,
            'color' => fake()->randomElement(['slate', 'blue', 'violet', 'amber', 'cyan']),
            'probability' => fake()->numberBetween(5, 90),
            'outcome' => StageOutcome::Open->value,
            'position' => 0,
        ];
    }

    public function won(): self
    {
        return $this->state(fn () => [
            'key' => 'won',
            'name' => 'Closed won',
            'color' => 'emerald',
            'probability' => 100,
            'outcome' => StageOutcome::Won->value,
        ]);
    }

    public function lost(): self
    {
        return $this->state(fn () => [
            'key' => 'lost',
            'name' => 'Closed lost',
            'color' => 'rose',
            'probability' => 0,
            'outcome' => StageOutcome::Lost->value,
        ]);
    }
}
