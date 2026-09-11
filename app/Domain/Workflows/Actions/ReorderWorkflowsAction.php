<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\Models\Workflow;
use Illuminate\Support\Facades\DB;

/**
 * Sets the order workflows run in, within one module.
 *
 * Scoped to a module on purpose. Renumbering every workflow in the application
 * because somebody dragged one in the leads list is the bug 4.4 had in
 * `ReorderPipelinesAction`, and it is invisible until two modules both have
 * workflows.
 *
 * Ids that do not belong to that module are ignored rather than refused: the
 * page may have been open while somebody else moved one.
 */
class ReorderWorkflowsAction
{
    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function __invoke(string $module, array $orderedIds): void
    {
        $ids = Workflow::query()
            ->where('module', $module)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($module, $orderedIds, $ids): void {
            $position = 0;

            foreach ($orderedIds as $id) {
                if (! in_array((int) $id, $ids, true)) {
                    continue;
                }

                Workflow::query()
                    ->where('module', $module)
                    ->whereKey((int) $id)
                    ->update(['position' => $position]);

                $position++;
            }
        });
    }
}
