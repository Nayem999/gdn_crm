<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\TicketComment;

class DeleteTicketCommentAction
{
    /**
     * Soft-deleted, like everything else on a ticket.
     *
     * What was said to a customer is part of the record even once somebody
     * thinks better of it, and an audit entry that points at a row which no
     * longer exists answers nothing.
     */
    public function __invoke(TicketComment $comment): void
    {
        $comment->delete();
    }
}
