<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\DTOs\WorkflowActionData;
use App\Domain\Workflows\DTOs\WorkflowData;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates or updates one workflow and its steps, together.
 *
 * Two things this is careful about:
 *
 * - **The module is written once, on create, and refused afterwards.** A
 *   workflow's conditions and its actions both name fields belonging to that
 *   module; moving it to another would leave every one of them pointing at a
 *   field that module does not have, and the workflow would quietly stop
 *   matching anything rather than fail.
 * - **Steps are reconciled, not replaced.** Deleting and re-inserting them
 *   would hand every step a new id on every save, and `workflow_run_steps`
 *   points at those ids — the whole execution history would detach from the
 *   steps it describes. So a step that carries an id is updated in place, one
 *   that does not is created, and only those genuinely gone are deleted.
 */
class SaveWorkflowAction
{
    /**
     * @throws RuntimeException when the module is not one the registry lists
     */
    public function __invoke(WorkflowData $data, ?Workflow $workflow = null): Workflow
    {
        if ($workflow === null && ! WorkflowModules::has($data->module)) {
            throw new RuntimeException('That is not a module a workflow can watch.');
        }

        return DB::transaction(function () use ($data, $workflow): Workflow {
            $workflow = $workflow === null
                ? $this->create($data)
                : $this->update($workflow, $data);

            $this->syncActions($workflow, $data->actions);

            return $workflow->fresh() ?? $workflow;
        });
    }

    private function create(WorkflowData $data): Workflow
    {
        $workflow = new Workflow;

        $workflow->forceFill([
            ...$data->toAttributes(),
            'created_by' => auth()->id(),
            // Appended rather than inserted: adding a workflow must not
            // reorder the ones already running.
            'position' => (int) Workflow::query()->where('module', $data->module)->max('position') + 1,
        ])->save();

        return $workflow;
    }

    private function update(Workflow $workflow, WorkflowData $data): Workflow
    {
        $attributes = $data->toAttributes();

        // The module is set on create and never again — see the class comment.
        unset($attributes['module']);

        $workflow->forceFill($attributes)->save();

        return $workflow;
    }

    /**
     * @param  array<int, WorkflowActionData>  $actions
     */
    private function syncActions(Workflow $workflow, array $actions): void
    {
        $kept = [];

        foreach ($actions as $data) {
            // An id is only honoured if it names a step of *this* workflow. A
            // payload carrying somebody else's step id would otherwise move it
            // between workflows.
            $step = $data->id === null
                ? null
                : $workflow->actions()->whereKey($data->id)->first();

            if ($step === null) {
                $step = new WorkflowAction(['workflow_id' => $workflow->id]);
            }

            $step->forceFill([
                ...$data->toAttributes(),
                'workflow_id' => $workflow->id,
            ])->save();

            $kept[] = $step->id;
        }

        $workflow->actions()->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
    }
}
