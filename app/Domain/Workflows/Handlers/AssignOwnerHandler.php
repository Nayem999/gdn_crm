<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Models\User;

/**
 * Hands the record to somebody.
 *
 * `assign_to` is a small vocabulary rather than a user id alone, because the
 * useful answers are mostly relative: give it to whoever owns the related
 * account, give it back to whoever created it. 5.7 adds the distributing
 * strategies — round robin, load-based, territory — as further entries here.
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

        $target = $this->resolve((string) $action->setting('assign_to'));

        if ($target === null) {
            return WorkflowStepOutcome::skipped('Nobody matched the assignment rule.');
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

    /**
     * The vocabulary a stored config may use.
     *
     * `user:<id>` is the only form that names somebody, and it is resolved
     * through a query rather than trusted — a config written when a user
     * existed outlives that user.
     */
    private function resolve(string $assignTo): ?User
    {
        if (! str_starts_with($assignTo, 'user:')) {
            return null;
        }

        return User::query()->whereKey((int) str($assignTo)->after('user:')->toString())->first();
    }
}
