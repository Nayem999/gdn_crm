<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\Ticket;
use App\Models\User;

/**
 * Hands a ticket to somebody else.
 *
 * Its own action rather than part of an update, because moving work between
 * agents is a different act from editing the ticket's details — and 9.2 sends a
 * notification on exactly this, which it could not do if reassignment were
 * indistinguishable from a typo correction.
 */
class AssignTicketAction
{
    public function __invoke(Ticket $ticket, User $agent): bool
    {
        if ($ticket->owner_id === $agent->id) {
            return false;
        }

        $ticket->forceFill(['owner_id' => $agent->id])->save();

        return true;
    }
}
