<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Models\Pipeline;
use Illuminate\Support\Facades\DB;

/**
 * Put the pipelines in the order the drag left them.
 *
 * The ids arrive from the browser, so they are matched against what actually
 * exists rather than trusted: an unknown id is dropped, and anything the
 * submitted order left out keeps its place after the ones that were listed.
 */
class ReorderPipelinesAction
{
    /**
     * @param  array<int, int|string>  $orderedIds
     * @param  string  $module  Whose pipelines are being ordered.
     * @return int How many rows moved.
     */
    public function __invoke(array $orderedIds, string $module = Pipeline::DEALS): int
    {
        // Scoped to one module's pipelines. Without it, reordering the leads
        // pipelines would renumber the deals ones, because position is shared
        // across the table.
        $known = Pipeline::query()->forModule($module)->ordered()->pluck('id')->all();

        $ordered = array_values(array_unique(array_filter(
            array_map('intval', $orderedIds),
            fn (int $id) => in_array($id, $known, true)
        )));

        // Anything the browser did not mention keeps its relative order behind
        // what it did, so a stale page cannot shuffle rows it never showed.
        $remaining = array_values(array_diff($known, $ordered));

        $moved = 0;

        DB::transaction(function () use ($ordered, $remaining, &$moved) {
            foreach ([...$ordered, ...$remaining] as $position => $id) {
                $moved += Pipeline::query()
                    ->whereKey($id)
                    ->where('position', '!=', $position)
                    ->update(['position' => $position]);
            }
        });

        return $moved;
    }
}
