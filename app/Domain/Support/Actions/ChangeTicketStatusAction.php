<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;

/**
 * Moves a ticket, and owns the two stamps that go with it.
 *
 * The only writer of `status`, which is why the column is out of
 * `Ticket::$fillable` — the same arrangement that keeps MoveDealStageAction the
 * only writer of a deal's stage. A form that could set it would be a second
 * path to "resolved", and the two would eventually disagree about
 * `resolved_at`.
 */
class ChangeTicketStatusAction
{
    /**
     * @return bool whether anything moved — false when it is already there, so
     *              a board drop into the column it came from reports no move
     */
    public function __invoke(Ticket $ticket, TicketStatus $status): bool
    {
        if ($ticket->status() === $status) {
            return false;
        }

        $ticket->forceFill([
            'status' => $status->value,

            // Stamped the first time a resolving status is reached and not
            // pushed forward afterwards: a ticket that went resolved, reopened
            // and resolved again was first resolved when it first was, and
            // 9.5's resolution time is measured from that. Moving back to an
            // open status clears it, because a ticket on somebody's queue that
            // still says it was resolved on Tuesday reads as done.
            'resolved_at' => $status->marksResolved() ? ($ticket->resolved_at ?? now()) : null,

            // Only closed means closed. Going from Closed back to Resolved
            // clears this, which is the honest answer — somebody has reopened
            // the conversation even if they think the fix stands.
            'closed_at' => $status === TicketStatus::Closed ? ($ticket->closed_at ?? now()) : null,
        ])->save();

        return true;
    }
}
