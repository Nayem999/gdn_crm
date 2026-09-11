<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Triggers\WorkflowDispatcher;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fires the workflows whose date has arrived.
 *
 * A date trigger has no event to hang off — nothing happens to a record when a
 * date passes — so it is swept for. Three things keep the sweep honest:
 *
 * - **It never fires retroactively.** Only moments at or after the workflow was
 *   created count. Otherwise switching on "three days after capture" would, on
 *   its first run, fire for every lead ever captured.
 * - **The claim is permanent.** A date arrives once per record, so the dedupe
 *   key is per record rather than per occasion, and a sweep that runs twice —
 *   or overlaps itself — cannot fire the same record again.
 * - **The column comes from the registry**, never from the stored string. A
 *   `trigger_field` that no longer names a real date column sweeps nothing
 *   rather than reaching SQL.
 */
class RunDateWorkflowsAction
{
    /**
     * How many records one workflow may fire for in a single sweep.
     *
     * A workflow switched on against a large table would otherwise queue tens
     * of thousands of runs in one tick. The remainder is taken by the next
     * sweep, which is a minute away.
     */
    public const BATCH = 500;

    public function __construct(private readonly WorkflowDispatcher $dispatcher) {}

    /**
     * @return int the number of runs started
     */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $started = 0;

        $workflows = Workflow::query()
            ->withTrigger(WorkflowTrigger::DateReached)
            ->with('actions')
            ->get();

        foreach ($workflows as $workflow) {
            if (! $workflow->isRunnable()) {
                continue;
            }

            $started += $this->sweep($workflow, $now);
        }

        return $started;
    }

    private function sweep(Workflow $workflow, Carbon $now): int
    {
        $query = $this->due($workflow, $now);

        if ($query === null) {
            return 0;
        }

        $started = 0;

        foreach ($query->limit(self::BATCH)->get() as $record) {
            $run = $this->dispatcher->dateReached($workflow, $record, [
                'field' => $workflow->trigger_field,
                'offset_minutes' => $workflow->date_offset_minutes ?? 0,
            ]);

            if ($run instanceof WorkflowRun) {
                $started++;
            }
        }

        return $started;
    }

    /**
     * The records whose moment has come and which have not been fired for.
     *
     * @return Builder<covariant Model>|null
     */
    private function due(Workflow $workflow, Carbon $now): ?Builder
    {
        $module = $workflow->module();
        $column = WorkflowModules::column($module, (string) $workflow->trigger_field);
        $model = WorkflowModules::modelClass($module);

        if ($column === null || $model === null) {
            return null;
        }

        // An offset of "three days before" means the moment arrives three days
        // earlier, so the cutoff moves the other way: date <= now + 3 days.
        $cutoff = $now->copy()->subMinutes($workflow->date_offset_minutes ?? 0);

        $query = $model::query()
            ->whereNotNull($column)
            ->where($column, '<=', $cutoff)
            // Not retroactive: the moment must be one that arrived after
            // somebody wrote the workflow.
            ->where($column, '>=', $workflow->created_at)
            ->orderBy($column);

        // A generated module keeps every module's records in one table.
        $custom = WorkflowModules::customModule($module);

        if ($custom !== null) {
            $query->where('custom_module_id', $custom->id);
        }

        // The unique dedupe key is the real guarantee; this only keeps the
        // sweep from re-reading records it has already fired for.
        return $query->whereNotExists(function ($sub) use ($workflow, $model) {
            $sub->select('id')
                ->from('workflow_runs')
                ->whereColumn('workflow_runs.subject_id', (new $model)->getTable().'.id')
                ->where('workflow_runs.subject_type', (new $model)->getMorphClass())
                ->where('workflow_runs.workflow_id', $workflow->id)
                ->where('workflow_runs.trigger_event', WorkflowTrigger::DateReached->value);
        });
    }
}
