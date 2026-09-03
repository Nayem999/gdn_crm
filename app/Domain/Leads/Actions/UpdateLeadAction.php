<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;

class UpdateLeadAction
{
    public function __construct(private readonly ScoreLeadAction $scoreLead) {}

    /**
     * Update a lead's details.
     *
     * The status is untouched here — ChangeLeadStatusAction owns it, so an
     * ordinary edit can never sidestep the transition rules.
     */
    public function __invoke(Lead $lead, LeadData $data): Lead
    {
        $attributes = $data->toAttributes();

        // The owner only moves when one was chosen, so an edit that leaves the
        // field alone cannot silently unassign the record.
        if ($data->ownerId !== null) {
            $attributes['owner_id'] = $data->ownerId;
        }

        $lead->update($attributes);

        // The edit may have changed something a scoring rule reads.
        return ($this->scoreLead)($lead);
    }
}
