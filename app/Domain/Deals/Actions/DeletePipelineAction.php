<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Remove a pipeline.
 *
 * The reasons it may be refused live on the model, so the button that is hidden
 * and the request that is turned away always give the same answer — a check
 * duplicated here would eventually disagree with the one on screen.
 */
class DeletePipelineAction
{
    /**
     * @throws RuntimeException when the pipeline cannot be removed
     */
    public function __invoke(Pipeline $pipeline): void
    {
        $blocker = $pipeline->deletionBlocker();

        if ($blocker !== null) {
            throw new RuntimeException($blocker);
        }

        DB::transaction(function () use ($pipeline) {
            // Stages go with it. Nothing points at them: a deal stores a stage
            // key, and this pipeline has no deals or the guard above refused.
            $pipeline->stages()->delete();
            $pipeline->delete();
        });
    }
}
