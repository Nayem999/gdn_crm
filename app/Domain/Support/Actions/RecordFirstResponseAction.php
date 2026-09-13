<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use Illuminate\Support\Carbon;

/**
 * Stops the first-response clock, the first time we actually answer.
 *
 * Three things are deliberately not an answer:
 *
 *   - an **internal note**, which the customer never sees;
 *   - the **customer's own** reply, which is them chasing us;
 *   - a **second** reply, because first means first.
 *
 * Getting any of those wrong would make a desk's first-response figure flatter
 * it, which is exactly the number a customer signed a contract about.
 */
class RecordFirstResponseAction
{
    /**
     * @return bool whether this comment was the first response
     */
    public function __invoke(Ticket $ticket, TicketComment $comment, ?Carbon $now = null): bool
    {
        if ($ticket->first_responded_at !== null
            || $comment->is_internal
            || $comment->from_customer) {
            return false;
        }

        $ticket->forceFill([
            'first_responded_at' => $comment->created_at ?? $now ?? now(),
        ])->save();

        return true;
    }
}
