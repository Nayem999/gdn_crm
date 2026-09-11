<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\WorkflowCache;

/**
 * Removes a workflow and its steps.
 *
 * Its **runs are left behind**, with the workflow's name copied onto each. The
 * foreign key nulls rather than cascades, which is deliberate: somebody
 * investigating why a record was changed last month needs the log to survive
 * the deletion of the thing that changed it. The steps themselves do cascade —
 * a definition nothing refers to any more is not history, and each run step
 * already keeps its own copy of the action type.
 */
class DeleteWorkflowAction
{
    public function __invoke(Workflow $workflow): void
    {
        $workflow->delete();

        app(WorkflowCache::class)->flush();
    }
}
