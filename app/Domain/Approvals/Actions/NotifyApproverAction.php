<?php

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Models\ApprovalLevel;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Notifications\Actions\DispatchNotificationAction;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Recipient;

/**
 * Tells somebody an approval is waiting on them.
 *
 * Through the 1.9 engine, so an administrator's channel matrix and a person's
 * own preferences apply — with one deliberate consequence worth stating: an
 * approval notification can be switched off, and the approval still waits. The
 * approvals screen is the source of truth; the notification is a prompt.
 */
class NotifyApproverAction
{
    public const EVENT = 'approval.requested';

    public function __invoke(ApprovalRequest $request, ApprovalLevel $level): int
    {
        $approver = $level->approver;

        if ($approver === null) {
            return 0;
        }

        return app(DispatchNotificationAction::class)->handle(
            self::EVENT,
            [Recipient::user($approver, RecipientType::AssignedAgent)],
            [
                'approval' => [
                    'summary' => $request->summary,
                    'module' => $request->moduleLabel(),
                    'workflow' => $request->workflow_name,
                    'due' => $level->due_at?->toDayDateTimeString() ?? 'no deadline',
                ],
                'app' => ['name' => config('app.name')],
            ],
            url: route('approvals.index'),
        );
    }
}
