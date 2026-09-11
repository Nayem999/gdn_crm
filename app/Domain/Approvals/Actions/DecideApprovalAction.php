<?php

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Jobs\RunWorkflow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records one person's answer, and carries out what it means.
 *
 * Approving is not the end of it: the last level approving releases the
 * workflow, which picks up from the step after the one that asked. Rejecting
 * ends both — the request and the run — because the steps the approval was
 * gating are exactly the ones that must not happen.
 *
 * A decision is only accepted from **the person being asked now**. A level three
 * approver answering early would skip the people whose agreement the chain
 * exists to collect, and no amount of good intention makes that the same
 * approval.
 */
class DecideApprovalAction
{
    public function __construct(private readonly NotifyApproverAction $notify) {}

    /**
     * @throws RuntimeException when this person is not the one being asked
     */
    public function __invoke(
        ApprovalRequest $request,
        User $decider,
        bool $approved,
        ?string $comment = null,
    ): ApprovalRequest {
        if (! $request->status()->isOpen()) {
            throw new RuntimeException('That approval has already been settled.');
        }

        $level = $request->currentLevel();

        if ($level === null || $level->approver_id !== $decider->id) {
            throw new RuntimeException('This approval is not waiting on you.');
        }

        return DB::transaction(function () use ($request, $level, $decider, $approved, $comment): ApprovalRequest {
            $level->forceFill([
                'status' => $approved ? ApprovalStatus::Approved->value : ApprovalStatus::Rejected->value,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'comment' => $comment,
            ])->save();

            return $approved
                ? $this->advance($request, $comment)
                : $this->settle($request, ApprovalStatus::Rejected, $comment);
        });
    }

    /**
     * Move to the next person, or release the workflow when there is none.
     */
    public function advance(ApprovalRequest $request, ?string $comment = null, ?Carbon $now = null): ApprovalRequest
    {
        $next = $request->levels()
            ->where('position', '>', $request->current_level)
            ->where('status', ApprovalStatus::Waiting->value)
            ->first();

        if ($next === null) {
            return $this->settle($request, ApprovalStatus::Approved, $comment, $now);
        }

        // The next level's clock starts now, not when the request was made:
        // somebody asked at nine o'clock gets their full time, however long the
        // person before them took.
        $hours = $this->hoursToRespond($request);

        $next->forceFill([
            'due_at' => $hours === null ? null : ($now ?? Carbon::now())->copy()->addHours($hours),
        ])->save();

        $request->forceFill(['current_level' => $next->position])->save();

        ($this->notify)($request, $next);

        return $request->fresh() ?? $request;
    }

    /**
     * Close the request, and tell the workflow what to do about it.
     */
    public function settle(
        ApprovalRequest $request,
        ApprovalStatus $outcome,
        ?string $comment = null,
        ?Carbon $now = null,
    ): ApprovalRequest {
        $request->forceFill([
            'status' => $outcome->value,
            'decision_comment' => $comment,
            'completed_at' => $now ?? Carbon::now(),
        ])->save();

        // Any level still waiting is moot once the request is settled — it must
        // not keep appearing on somebody's list, and the escalation sweep must
        // not pick it up.
        $request->levels()
            ->where('status', ApprovalStatus::Waiting->value)
            ->update(['status' => ApprovalStatus::Cancelled->value]);

        $this->releaseRun($request, $outcome);

        return $request->fresh() ?? $request;
    }

    /**
     * Let the paused run carry on, or stop it.
     */
    private function releaseRun(ApprovalRequest $request, ApprovalStatus $outcome): void
    {
        $run = $request->run;

        if ($run === null || $run->status() !== WorkflowRunStatus::AwaitingApproval) {
            return;
        }

        if (! $outcome->letsWorkThrough()) {
            $run->forceFill([
                'status' => WorkflowRunStatus::Rejected->value,
                'message' => $outcome === ApprovalStatus::Expired
                    ? 'Nobody answered the approval in time.'
                    : 'The approval was rejected.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        // Back to pending, then queued: the executor only picks up a run in a
        // state it recognises, and going through the same job means an approved
        // workflow finishes exactly the way an unapproved one would have.
        $run->forceFill([
            'status' => WorkflowRunStatus::Pending->value,
            'resume_from_position' => $request->resume_from_position,
        ])->save();

        RunWorkflow::dispatch($run->id);
    }

    /**
     * How long each level is given, from the step that asked.
     *
     * Read from the action rather than stored per level, so a workflow whose
     * deadline is lengthened affects the levels that have not started yet —
     * and never the ones already counting down.
     */
    private function hoursToRespond(ApprovalRequest $request): ?int
    {
        $hours = $request->workflow_action_id === null
            ? null
            : WorkflowAction::query()
                ->whereKey($request->workflow_action_id)
                ->first()?->setting('hours_to_respond');

        return $hours === null || $hours === '' ? null : max(1, (int) $hours);
    }
}
