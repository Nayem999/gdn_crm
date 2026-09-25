<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Leads\Actions\SyncLeadAssigneesAction;
use App\Domain\Leads\Models\Lead;
use App\Domain\Workflows\Assignment\AssignmentResolver;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;

/**
 * Hands the record to somebody.
 *
 * Who it goes to is worked out by `AssignmentResolver`, which holds the five
 * strategies: a named person, whoever already owns it, round robin through a
 * pool, whoever is carrying the least, or by territory. Keeping them there
 * rather than here is what lets 5.7's distribution be tested on its own, without
 * a workflow, a run and a record standing in the way of counting who got what.
 *
 * An assignment that resolves to nobody is **skipped, not failed**: a workflow
 * that cannot find a candidate has nothing to do, and marking that a failure
 * would put a red row in the log every night for a rule that is simply not
 * applicable yet.
 */
class AssignOwnerHandler implements WorkflowActionHandler
{
    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome
    {
        $record = $context->subject;

        if ($record === null) {
            return WorkflowStepOutcome::skipped('There is no record to assign.');
        }

        $target = app(AssignmentResolver::class)->resolve($action, $context);

        if ($target === null) {
            return WorkflowStepOutcome::skipped('Nobody matched the assignment rule.');
        }

        // Leads have no owner_id column to force-fill: several people can be
        // assigned at once, so a workflow "assigning" one adds them alongside
        // whoever else is already there rather than replacing an owner.
        if ($record instanceof Lead) {
            if ($record->assignedUsers->contains('id', $target->id)) {
                return WorkflowStepOutcome::skipped($target->name.' is already assigned to this record.');
            }

            app(SyncLeadAssigneesAction::class)->add($record, $target);

            return WorkflowStepOutcome::success(
                'Assigned to '.$target->name,
                ['user_id' => $target->id],
            );
        }

        if ((int) $record->getAttribute('owner_id') === $target->id) {
            return WorkflowStepOutcome::skipped($target->name.' already owns this record.');
        }

        $record->forceFill(['owner_id' => $target->id])->save();

        return WorkflowStepOutcome::success(
            'Assigned to '.$target->name,
            ['owner_id' => $target->id],
        );
    }
}
