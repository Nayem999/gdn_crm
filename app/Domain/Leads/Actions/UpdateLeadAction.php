<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateLeadAction
{
    public function __construct(
        private readonly ScoreLeadAction $scoreLead,
        private readonly SyncLeadAssigneesAction $syncAssignees,
        private readonly AnnounceLeadAssignmentAction $announce,
    ) {}

    /**
     * Update a lead's details.
     *
     * The status is untouched here — ChangeLeadStatusAction owns it, so an
     * ordinary edit can never sidestep the transition rules.
     */
    public function __invoke(Lead $lead, LeadData $data, ?User $actor = null): Lead
    {
        // Whoever is signed in, when the caller did not say: the form and the
        // API both act for one. A queued ingestion has nobody, and is
        // described as an automation.
        $signedIn = auth()->user();
        $actor ??= $signedIn instanceof User ? $signedIn : null;

        $assignedBefore = $lead->assignees()->pluck('user_id')->all();
        $ownerBefore = $lead->lead_owner_id;

        // Wrapped together: a "record updated"/"field changed" workflow's
        // dispatch waits for this transaction to commit (WorkflowObserver),
        // so reading "the record owner" sees the sync below rather than the
        // set as it stood before this edit.
        DB::transaction(function () use ($lead, $data): void {
            $lead->update($data->toAttributes());

            // Null means the caller has no opinion on who is assigned — an
            // ingested update, say — so the existing set is left exactly as
            // it was rather than read back in just to hand unchanged.
            if ($data->assignees !== null) {
                $this->syncAssignees->handle($lead, $data->assignees);
            }
        });

        // Only the people this edit newly put on the lead.
        ($this->announce)(
            $lead,
            array_values(array_diff($lead->assignees()->pluck('user_id')->all(), $assignedBefore)),
            $lead->lead_owner_id !== $ownerBefore ? $lead->lead_owner_id : null,
            $actor,
        );

        // The edit may have changed something a scoring rule reads.
        return ($this->scoreLead)($lead->refresh());
    }
}
