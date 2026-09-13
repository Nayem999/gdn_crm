<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\Ticket;

class DeleteTicketAction
{
    /**
     * Soft-deleted, so what was said on it survives.
     *
     * A ticket is a conversation with a customer. Removing the row would take
     * its notes, its documents and its history with it, and "we have no record
     * of that" is the worst answer a support desk can give.
     */
    public function __invoke(Ticket $ticket): void
    {
        $ticket->delete();
    }
}
