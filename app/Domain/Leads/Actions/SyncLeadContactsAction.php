<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadContact;

/**
 * Make a lead's linked people exactly the given contacts, in the given order.
 *
 * The only writer of lead_contacts outside a duplicate merge. Rows are kept
 * where the person stays, so a re-save only moves positions rather than
 * churning ids and timestamps.
 */
class SyncLeadContactsAction
{
    /**
     * @param  array<int, int>  $contactIds
     */
    public function handle(Lead $lead, array $contactIds): void
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));

        LeadContact::query()
            ->where('lead_id', $lead->id)
            ->whereNotIn('contact_id', $contactIds)
            ->delete();

        foreach ($contactIds as $position => $contactId) {
            LeadContact::query()->updateOrCreate(
                ['lead_id' => $lead->id, 'contact_id' => $contactId],
                ['position' => $position],
            );
        }

        $lead->unsetRelation('contacts');
    }
}
