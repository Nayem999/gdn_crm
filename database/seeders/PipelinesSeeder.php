<?php

namespace Database\Seeders;

use App\Domain\Deals\Actions\SetDefaultPipelineAction;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use Illuminate\Database\Seeder;

class PipelinesSeeder extends Seeder
{
    public const DEFAULT_NAME = 'Standard sales';

    /**
     * The pipeline an installation starts with.
     *
     * Its stages mirror DealStage exactly — same keys, labels, colours and
     * probabilities — because a deal stores the stage *key*, and 2.6's deals
     * already hold those values. Seeding anything else would leave them
     * pointing at stages that do not exist.
     *
     * Safe to re-run: it fills gaps rather than resetting, so an administrator
     * who has renamed a stage does not lose the change.
     */
    public function run(): void
    {
        $pipeline = Pipeline::query()->firstOrCreate(
            ['name' => self::DEFAULT_NAME],
            [
                'description' => 'The route every deal follows unless another pipeline is chosen.',
                'position' => 0,
            ]
        );

        foreach (DealStage::pipeline() as $position => $stage) {
            PipelineStage::query()->firstOrCreate(
                ['pipeline_id' => $pipeline->id, 'key' => $stage->value],
                [
                    'name' => $stage->label(),
                    'color' => $stage->color(),
                    'probability' => $stage->probability(),
                    'outcome' => $this->outcomeFor($stage)->value,
                    'position' => $position,
                ]
            );
        }

        app(SetDefaultPipelineAction::class)->ensureOneExists();
    }

    private function outcomeFor(DealStage $stage): StageOutcome
    {
        return match ($stage) {
            DealStage::Won => StageOutcome::Won,
            DealStage::Lost => StageOutcome::Lost,
            default => StageOutcome::Open,
        };
    }
}
