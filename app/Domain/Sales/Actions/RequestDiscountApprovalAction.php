<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Approvals\Actions\NotifyApproverAction;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalLevel;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Products\Cpq\DiscountRules;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asks somebody to agree to a discount over the limit.
 *
 * Reuses the 5.6 approval machinery rather than inventing a second one: the
 * screen where approvals are answered, the escalation sweep and the audit of
 * who agreed to what already exist, and a CPQ approval that lived somewhere
 * else would be a second inbox nobody watches.
 *
 * There is no workflow behind it — `workflow_id` and `workflow_run_id` stay
 * null, which they are nullable for. What the approval gates is the quote going
 * out, and `SendQuoteAction` is what enforces that.
 */
class RequestDiscountApprovalAction
{
    public function __construct(
        private readonly DiscountRules $rules,
        private readonly NotifyApproverAction $notify,
    ) {}

    /**
     * @param  array<int, int>  $approverIds
     *
     * @throws RuntimeException when there is nothing to approve, or nobody to ask
     */
    public function __invoke(Quote $quote, array $approverIds, ?int $hoursToRespond = null): ApprovalRequest
    {
        if (! $this->rules->needsApproval($quote)) {
            throw new RuntimeException('This quote is within the discount limit — it needs no approval.');
        }

        $existing = $this->openRequestFor($quote);

        if ($existing !== null) {
            throw new RuntimeException('This quote is already waiting on an approval.');
        }

        $approvers = User::query()->whereIn('id', $approverIds)->get();

        if ($approvers->isEmpty()) {
            throw new RuntimeException('There is nobody to ask for this approval.');
        }

        return DB::transaction(function () use ($quote, $approvers, $hoursToRespond): ApprovalRequest {
            $request = ApprovalRequest::query()->create([
                'workflow_id' => null,
                'workflow_run_id' => null,
                'workflow_action_id' => null,
                // Named for what asked, so the approvals screen reads sensibly
                // beside workflow-raised ones.
                'workflow_name' => 'Discount approval',
                'module' => 'quotes',
                'subject_type' => $quote->getMorphClass(),
                'subject_id' => $quote->id,
                'summary' => $this->rules->summaryFor($quote),
                'status' => ApprovalStatus::Waiting->value,
                'current_level' => 0,
                'resume_from_position' => 0,
                'requested_at' => now(),
            ]);

            foreach ($approvers->values() as $position => $approver) {
                ApprovalLevel::query()->create([
                    'approval_request_id' => $request->id,
                    'position' => $position,
                    'approver_id' => $approver->id,
                    'status' => ApprovalStatus::Waiting->value,
                    'due_at' => $position === 0 && $hoursToRespond !== null
                        ? now()->addHours($hoursToRespond)
                        : null,
                ]);
            }

            $first = $request->levels()->first();

            if ($first !== null) {
                ($this->notify)($request, $first);
            }

            return $request->fresh() ?? $request;
        });
    }

    /**
     * The approval this quote is waiting on, if any.
     */
    public function openRequestFor(Quote $quote): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->open()
            ->where('subject_type', $quote->getMorphClass())
            ->where('subject_id', $quote->id)
            ->first();
    }

    /**
     * Whether a discount approval has been given for this quote **as it stands**.
     *
     * Matched on what was approved, not on when. An approval given for a 15%
     * discount must not license a 40% one typed afterwards — and comparing
     * timestamps cannot tell the two apart, because an edit made in the same
     * second as the decision is not "after" it at second granularity.
     *
     * So the stored summary — which carries the discount and the total — is
     * compared against the same summary for the quote now. Identical means the
     * offer is the one somebody agreed to; different means it is a different
     * offer and needs agreeing again.
     */
    public function isApproved(Quote $quote): bool
    {
        return ApprovalRequest::query()
            ->where('subject_type', $quote->getMorphClass())
            ->where('subject_id', $quote->id)
            ->where('status', ApprovalStatus::Approved->value)
            ->where('summary', $this->rules->summaryFor($quote))
            ->exists();
    }
}
