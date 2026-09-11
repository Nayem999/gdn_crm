<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Approvals\Actions\RequestApprovalAction;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Domain\Workflows\WorkflowModules;

/**
 * Stops the workflow and asks somebody.
 *
 * The only step whose outcome pauses the run: everything after it waits for an
 * answer. That is what separates an approval from a notification — a
 * notification tells somebody while the work happens anyway.
 *
 * The summary is written **now**, from the steps that come after this one, and
 * stored on the request. Deriving it when the approval is read would show
 * whatever the workflow says today, and somebody approving "give a 20% discount"
 * must not later be recorded as having approved 40%.
 */
class RequestApprovalHandler implements WorkflowActionHandler
{
    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome
    {
        $approverIds = $this->approverIds($action);

        if ($approverIds === []) {
            // Nobody to ask. Failing rather than skipping, because letting the
            // remaining steps run unapproved is exactly what this step exists
            // to prevent.
            return WorkflowStepOutcome::failed('This approval names nobody to ask, so nothing was approved.');
        }

        $hours = $action->setting('hours_to_respond');

        $request = app(RequestApprovalAction::class)(
            $context,
            $action,
            $approverIds,
            $hours === null || $hours === '' ? null : max(1, (int) $hours),
            $this->summary($action, $context),
        );

        if ($request === null) {
            return WorkflowStepOutcome::failed('None of the people named for this approval still exist.');
        }

        return WorkflowStepOutcome::awaitingApproval(
            'Asked '.($request->levels()->first()?->approver->name ?? 'an approver'),
            ['approval_request_id' => $request->id],
        );
    }

    /**
     * @return array<int, int>
     */
    private function approverIds(WorkflowAction $action): array
    {
        $configured = $action->setting('approvers');

        if (! is_array($configured)) {
            return [];
        }

        $ids = [];

        foreach ($configured as $value) {
            $id = (int) str((string) $value)->after('user:')->toString();

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * What the approver is being asked to agree to, in words.
     */
    private function summary(WorkflowAction $action, WorkflowContext $context): string
    {
        $written = trim((string) $action->setting('summary'));

        if ($written !== '') {
            return $written;
        }

        $record = $context->subject === null
            ? WorkflowModules::label($context->module())
            : CustomFieldRegistry::recordLabel($context->subject);

        $remaining = $context->workflow->actions()
            ->where('is_active', true)
            ->where('position', '>', $action->position)
            ->get()
            ->map(fn (WorkflowAction $step): string => strtolower($step->type()->label()))
            ->all();

        return $remaining === []
            ? '"'.$context->workflow->name.'" on '.$record
            : '"'.$context->workflow->name.'" on '.$record.': '.implode(', ', $remaining);
    }
}
