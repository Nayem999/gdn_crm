<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateLeadAction
{
    public function __construct(
        private readonly ScoreLeadAction $scoreLead,
        private readonly SyncLeadAssigneesAction $syncAssignees,
        private readonly AnnounceLeadAssignmentAction $announce,
    ) {}

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

        // Wrapped together: a "record created" workflow's dispatch waits for
        // this transaction to commit (WorkflowObserver), so reading "the
        // record owner" from a rule like record_owner or a notification
        // recipient sees the assignees below rather than a lead that, for one
        // instant, has none.
        $lead = DB::transaction(function () use ($attributes, $data, $actor): Lead {
            $lead = Lead::create($attributes);

            // A lead always has at least one assignee; unassigned records are
            // how visibility scoping springs a leak. Falling back to the
            // acting user mirrors what owner_id used to do.
            $this->syncAssignees->handle($lead, $data->assignees ?? [['user_id' => $actor->id, 'priority' => null]]);

            return $lead;
        });

        // Everybody put on it, and its owner, except the person who made it.
        ($this->announce)(
            $lead,
            $lead->assignees()->pluck('user_id')->all(),
            $lead->lead_owner_id,
            $actor,
        );

        return ($this->scoreLead)($lead->refresh());
    }
}
