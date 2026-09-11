<?php

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\EscalationOutcome;
use App\Domain\Approvals\Models\ApprovalLevel;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Workflows\Models\WorkflowAction;
use Illuminate\Support\Carbon;

/**
 * Deals with approvals nobody answered in time.
 *
 * What "in time" means is per level, and what happens next is per approval
 * step — see `EscalationOutcome` for why that is configured rather than assumed.
 * The default, and the one worth defending: pass it to the next person, and if
 * there is nobody left, reject. Silence is not agreement.
 *
 * A level that ran out is marked `Expired` with `escalated_at` set, never
 * `Rejected`: "nobody replied" and "somebody said no" are different facts, and
 * an audit of a decision is exactly where the difference matters.
 */
class EscalateApprovalsAction
{
    public function __construct(private readonly DecideApprovalAction $decide) {}

    /**
     * @return int how many levels ran out
     */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $overdue = ApprovalLevel::query()
            ->overdue($now)
            ->with('request')
            ->orderBy('id')
            ->get();

        $escalated = 0;

        foreach ($overdue as $level) {
            $request = $level->request;

            // A level belonging to a request that has since been settled is
            // moot; settle() cancels them, so this only catches a race.
            if ($request === null || ! $request->status()->isOpen()) {
                continue;
            }

            $this->expire($level, $now);
            $this->act($request, $now);

            $escalated++;
        }

        return $escalated;
    }

    private function expire(ApprovalLevel $level, Carbon $now): void
    {
        $level->forceFill([
            'status' => ApprovalStatus::Expired->value,
            'escalated_at' => $now,
        ])->save();
    }

    private function act(ApprovalRequest $request, Carbon $now): void
    {
        $outcome = $this->outcomeFor($request);
        $reason = 'Nobody answered within the time allowed.';

        match ($outcome) {
            EscalationOutcome::Approve => $this->decide->settle($request, ApprovalStatus::Approved, $reason, $now),
            EscalationOutcome::Reject => $this->decide->settle($request, ApprovalStatus::Expired, $reason, $now),
            // advance() settles as Approved when there is nobody left, which is
            // not what an unanswered escalation should do, so the end of the
            // chain is handled here instead.
            EscalationOutcome::Escalate => $this->escalate($request, $reason, $now),
        };
    }

    private function escalate(ApprovalRequest $request, string $reason, Carbon $now): void
    {
        $hasNext = $request->levels()
            ->where('position', '>', $request->current_level)
            ->where('status', ApprovalStatus::Waiting->value)
            ->exists();

        if (! $hasNext) {
            // Nobody left to ask. Expired rather than approved: an approval
            // that lets itself through when ignored is not an approval.
            $this->decide->settle($request, ApprovalStatus::Expired, $reason, $now);

            return;
        }

        $this->decide->advance($request, $reason, $now);
    }

    private function outcomeFor(ApprovalRequest $request): EscalationOutcome
    {
        $configured = $request->workflow_action_id === null
            ? null
            : WorkflowAction::query()->whereKey($request->workflow_action_id)->first()?->setting('on_timeout');

        return EscalationOutcome::tryFrom((string) $configured) ?? EscalationOutcome::Escalate;
    }
}
