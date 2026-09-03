<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Models\Lead;
use App\Models\User;

/**
 * Hand a lead to somebody.
 *
 * Its own action because assignment is how a lead reaches the person who will
 * work it, and later phases hang a notification and a workflow trigger off
 * exactly this moment.
 */
class AssignLeadAction
{
    /**
     * @return bool False when the lead already belongs to that person.
     */
    public function __invoke(Lead $lead, User $owner): bool
    {
        if ($lead->owner_id === $owner->id) {
            return false;
        }

        $lead->forceFill(['owner_id' => $owner->id])->save();

        return true;
    }
}
