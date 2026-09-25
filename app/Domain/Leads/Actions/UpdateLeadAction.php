<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;

class UpdateLeadAction
{
    public function __construct(
        private readonly ScoreLeadAction $scoreLead,
        private readonly SyncLeadAssigneesAction $syncAssignees,
    ) {}

    /**
     * Update a lead's details.
     *
     * The status is untouched here — ChangeLeadStatusAction owns it, so an
     * ordinary edit can never sidestep the transition rules.
     */
    public function __invoke(Lead $lead, LeadData $data): Lead
    {
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

        // The edit may have changed something a scoring rule reads.
        return ($this->scoreLead)($lead->refresh());
    }
}
