<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use App\Domain\Deals\PipelineStatusCache;
use Illuminate\Support\Facades\DB;

/**
 * Put one pipeline's stages in the order the drag left them.
 *
 * Scoped to the pipeline throughout: the keys arrive from the browser, and a
 * key belonging to another pipeline must not be able to move anything here.
 */
class ReorderPipelineStagesAction
{
    /**
     * @param  array<int, string>  $orderedKeys
     * @return int How many rows moved.
     */
    public function __invoke(Pipeline $pipeline, array $orderedKeys): int
    {
        $known = $pipeline->stages()->pluck('key')->all();

        $ordered = array_values(array_unique(array_filter(
            array_map('strval', $orderedKeys),
            fn (string $key) => in_array($key, $known, true)
        )));

        $remaining = array_values(array_diff($known, $ordered));

        $moved = 0;

        DB::transaction(function () use ($pipeline, $ordered, $remaining, &$moved) {
            foreach ([...$ordered, ...$remaining] as $position => $key) {
                $moved += PipelineStage::query()
                    ->where('pipeline_id', $pipeline->getKey())
                    ->where('key', $key)
                    ->where('position', '!=', $position)
                    ->update(['position' => $position]);
            }
        });

        // The status sets are memoised per request; a renamed, reordered or
        // removed stage has to show on the very next render.
        app(PipelineStatusCache::class)->flush();

        return $moved;
    }
}
