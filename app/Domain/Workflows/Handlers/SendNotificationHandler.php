<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\Actions\DispatchNotificationAction;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Recipient;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowMergeData;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Models\User;

/**
 * Tells somebody inside the organisation.
 *
 * Through the 1.9 engine rather than round it, which is what makes an
 * administrator's channel matrix, a person's own preferences and quiet hours
 * apply to workflow notifications exactly as they do to everything else. The
 * engine queues every delivery; nothing is sent inside the run.
 *
 * The event key is fixed at `workflow.notified`. A workflow cannot name an
 * arbitrary event, because the event is what the matrix governs — letting a
 * config choose one would let a workflow borrow another event's channel
 * settings and its audience.
 */
class SendNotificationHandler implements WorkflowActionHandler
{
    public const EVENT = 'workflow.notified';

    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome
    {
        $recipients = $this->recipients((string) $action->setting('recipient'), $context);

        if ($recipients === []) {
            return WorkflowStepOutcome::skipped('Nobody matched the recipient rule.');
        }

        $data = [
            ...WorkflowMergeData::for($context->module(), $context->subject, $context->trigger),
            'workflow' => ['name' => $context->workflow->name],
            'message' => (string) $action->setting('message'),
        ];

        $queued = app(DispatchNotificationAction::class)->handle(self::EVENT, $recipients, $data);

        if ($queued === 0) {
            // Not a failure: the matrix or a personal preference legitimately
            // switched every channel off, and the engine's own log says so.
            return WorkflowStepOutcome::skipped('Every channel was switched off for these recipients.');
        }

        return WorkflowStepOutcome::success(
            $queued.' '.str('notification')->plural($queued).' queued',
            ['queued' => $queued],
        );
    }

    /**
     * @return array<int, Recipient>
     */
    private function recipients(string $rule, WorkflowContext $context): array
    {
        $user = match (true) {
            $rule === 'record_owner' => $this->owner($context),
            str_starts_with($rule, 'user:') => User::query()->whereKey((int) str($rule)->after('user:')->toString())->first(),
            default => null,
        };

        return $user === null ? [] : [Recipient::user($user, RecipientType::AssignedAgent)];
    }

    private function owner(WorkflowContext $context): ?User
    {
        $subject = $context->subject;

        // Leads have no owner_id column: several people can be assigned at
        // once, so "the record owner" is the highest-priority one — the same
        // stand-in AssignmentResolver's own RecordOwner strategy uses.
        if ($subject instanceof Lead) {
            return $subject->primaryAssignee();
        }

        $ownerId = $subject?->getAttribute('owner_id');

        return $ownerId === null ? null : User::query()->whereKey((int) $ownerId)->first();
    }
}
