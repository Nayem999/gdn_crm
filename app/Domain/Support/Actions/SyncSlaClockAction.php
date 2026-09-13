<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * Stops and starts the clock as a ticket moves.
 *
 * On hold pauses it; everything else runs. **Pending does not pause**, and that
 * is the distinction the two statuses exist for: waiting on a supplier or a
 * part is our delay, and waiting for the customer to answer is theirs. A desk
 * that paused on pending could stop every clock by asking a question.
 *
 * Resuming shifts both due times forward by however long the hold lasted, so
 * the promise is always the same amount of *our* time.
 */
class SyncSlaClockAction
{
    /**
     * @return bool whether the clock's state changed
     */
    public function __invoke(Ticket $ticket, TicketStatus $status, ?Carbon $now = null): bool
    {
        $now ??= now();
        $paused = $ticket->sla_paused_at !== null;
        $shouldPause = $status === TicketStatus::OnHold;

        if ($paused === $shouldPause) {
            return false;
        }

        if ($shouldPause) {
            $ticket->forceFill(['sla_paused_at' => $now])->save();

            return true;
        }

        $held = (int) $ticket->sla_paused_at->diffInSeconds($now);

        $ticket->forceFill([
            'sla_paused_at' => null,
            'sla_paused_seconds' => $ticket->sla_paused_seconds + $held,
            'first_response_due_at' => $ticket->first_response_due_at?->copy()->addSeconds($held),
            'resolution_due_at' => $ticket->resolution_due_at?->copy()->addSeconds($held),
        ])->save();

        return true;
    }
}
