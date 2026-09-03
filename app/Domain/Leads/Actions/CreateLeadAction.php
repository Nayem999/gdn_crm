<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Models\User;

class CreateLeadAction
{
    public function __construct(private readonly ScoreLeadAction $scoreLead) {}

    /**
     * Capture a lead.
     *
     * Every lead starts at New whatever the caller asks for: a status is
     * something a lead earns by being worked, not something a capture form or
     * an inbound payload gets to assert.
     */
    public function __invoke(LeadData $data, User $actor): Lead
    {
        $attributes = $data->toAttributes();

        $attributes['status'] = LeadStatus::New->value;
        $attributes['status_changed_at'] = now();
        // A lead always has an owner; unassigned records are how visibility
        // scoping springs a leak.
        $attributes['owner_id'] = $data->ownerId ?? $actor->id;

        $lead = Lead::create($attributes);

        return ($this->scoreLead)($lead);
    }
}
