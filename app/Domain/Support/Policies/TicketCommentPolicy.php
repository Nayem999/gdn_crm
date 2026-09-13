<?php

namespace App\Domain\Support\Policies;

use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use App\Models\User;

/**
 * A comment carries no authorization of its own.
 *
 * Every question is answered by asking about the ticket it hangs off, so a
 * comment can never be a way around the support module's access level — the
 * same arrangement NotePolicy uses for the timeline.
 */
class TicketCommentPolicy
{
    public function view(User $user, TicketComment $comment): bool
    {
        return $this->ticketIsVisible($user, $comment);
    }

    /**
     * Replying is updating the ticket.
     *
     * Deliberately not its own permission: answering a customer is the work of
     * the module, and somebody who may move a ticket on but not say why would
     * be a strange thing to configure.
     */
    public function create(User $user, Ticket $ticket): bool
    {
        return $user->can('update', $ticket);
    }

    /**
     * Only the author edits their own words, and only while they are still
     * theirs to edit — an administrator rewriting what a colleague said to a
     * customer is not a correction, it is a forgery.
     */
    public function update(User $user, TicketComment $comment): bool
    {
        return $comment->author_id === $user->id
            && ! $comment->from_customer
            && $this->ticketIsVisible($user, $comment);
    }

    /**
     * The author, or somebody who may remove the ticket itself.
     *
     * A reply sent to a customer in error is the one thing a desk does need to
     * be able to take down, and the person who wrote it is not always the one
     * who notices.
     */
    public function delete(User $user, TicketComment $comment): bool
    {
        $ticket = $comment->ticket;

        return ($comment->author_id === $user->id || $user->can('delete', $ticket))
            && $this->ticketIsVisible($user, $comment);
    }

    private function ticketIsVisible(User $user, TicketComment $comment): bool
    {
        return $user->can('view', $comment->ticket);
    }
}
