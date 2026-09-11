<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;

/**
 * One kind of thing a workflow step can do.
 *
 * A handler **returns** an outcome rather than throwing: a step that cannot do
 * its job is an ordinary event the log records, not an exception that takes the
 * rest of the workflow with it. The runner catches throwables too, but that is
 * a backstop for the unforeseen rather than the way failure is meant to travel.
 */
interface WorkflowActionHandler
{
    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome;
}
