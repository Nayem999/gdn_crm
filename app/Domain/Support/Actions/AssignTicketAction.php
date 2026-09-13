<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketNotifications;
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
    public function __construct(private readonly TicketNotifications $notifications) {}

    public function __invoke(Ticket $ticket, User $agent, ?User $actor = null): bool
    {
        if ($ticket->owner_id === $agent->id) {
            return false;
        }

        $ticket->forceFill(['owner_id' => $agent->id])->save();

        // setRelation, not refresh(): the merge data names the agent, and a
        // stale owner relation would put the previous one in the message
        // telling somebody they now have it.
        $ticket->setRelation('owner', $agent);

        $this->notifications->assigned($ticket, $actor);

        return true;
    }
}
