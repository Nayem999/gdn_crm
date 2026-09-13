<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * What a breach actually does, beyond telling somebody.
 *
 * Escalation raises the priority by one step and stamps when it happened. It
 * deliberately does **not** reassign: moving a ticket off the agent who is
 * already late on it loses the only person with any context, and a desk that
 * wants a different owner has the assign action and a notification saying to
 * use it.
 *
 * It also does not re-run the SLA clock. A ticket that has already breached its
 * promise does not get a fresh, longer one because escalating made it urgent —
 * that would turn a missed deadline into a reset button.
 */
class EscalateTicketAction
{
    /**
     * @return bool whether anything changed
     */
    public function __invoke(Ticket $ticket, ?Carbon $now = null): bool
    {
        if ($ticket->escalated_at !== null) {
            // Once per ticket. A sweep every minute must not walk a ticket up
            // to urgent one step at a time.
            return false;
        }

        $raised = $this->nextUp($ticket->priority());

        $ticket->forceFill([
            'priority' => $raised->value,
            'escalated_at' => $now ?? now(),
        ])->save();

        return true;
    }

    private function nextUp(TicketPriority $priority): TicketPriority
    {
        return TicketPriority::tryFrom($priority->value + 1) ?? $priority;
    }
}
