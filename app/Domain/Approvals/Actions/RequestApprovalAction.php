<?php

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalLevel;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens an approval and asks the first person in the chain.
 *
 * The approvers are resolved **here, once**, and stored as levels. Resolving
 * them later would mean an approval that changes who it is asking because
 * somebody edited the workflow while it was waiting — and an audit of a
 * decision has to be able to say who was asked, not who would be asked now.
 *
 * Only the first level starts waiting. The rest sit dormant until the chain
 * reaches them, so "what is waiting for me" is a question with one answer per
 * request.
 */
class RequestApprovalAction
{
    public function __construct(private readonly NotifyApproverAction $notify) {}

    /**
     * @param  array<int, int>  $approverIds
     */
    public function __invoke(
        WorkflowContext $context,
        WorkflowAction $step,
        array $approverIds,
        ?int $hoursToRespond,
        string $summary,
    ): ?ApprovalRequest {
        // Resolved through a query: a config written when somebody worked here
        // outlives them leaving.
        $approvers = User::query()
            ->whereIn('id', $approverIds)
            ->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $approverIds ?: [0])).')')
            ->get();

        if ($approvers->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($context, $step, $approvers, $hoursToRespond, $summary): ApprovalRequest {
            $request = ApprovalRequest::query()->create([
                'workflow_id' => $context->workflow->id,
                'workflow_run_id' => $context->run->id,
                'workflow_action_id' => $step->id,
                // Copied, so the request still reads as something after the
                // workflow is edited or deleted.
                'workflow_name' => $context->workflow->name,
                'module' => $context->module(),
                'subject_type' => $context->subject?->getMorphClass(),
                'subject_id' => $context->subject?->getKey(),
                'summary' => $summary,
                'status' => ApprovalStatus::Waiting->value,
                'current_level' => 0,
                'resume_from_position' => $step->position + 1,
                'requested_at' => now(),
            ]);

            foreach ($approvers as $position => $approver) {
                ApprovalLevel::query()->create([
                    'approval_request_id' => $request->id,
                    'position' => $position,
                    'approver_id' => $approver->id,
                    'status' => ApprovalStatus::Waiting->value,
                    // Only the level being asked has a clock. A later level's
                    // time starts when the chain reaches it, not when the
                    // request was made.
                    'due_at' => $position === 0 && $hoursToRespond !== null
                        ? now()->addHours($hoursToRespond)
                        : null,
                ]);
            }

            $first = $request->levels()->first();

            if ($first !== null) {
                ($this->notify)($request, $first);
            }

            return $request;
        });
    }
}
