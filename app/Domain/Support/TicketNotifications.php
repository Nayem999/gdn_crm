<?php

namespace App\Domain\Support;

use App\Domain\Notifications\Notifier;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use App\Models\User;

/**
 * Every ticket notification is fired from here.
 *
 * The actions own the records; this owns the telling. Keeping it in one class
 * means the seven events cannot drift on who they reach or what they carry, and
 * an action that changes a ticket has one line to add rather than a recipient
 * list to reinvent.
 *
 * Nothing here decides whether a message is actually sent. The admin matrix,
 * the recipient's own preferences and the channel drivers do that, in that
 * order — see .ai/rules/notifications.md.
 */
class TicketNotifications
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly TicketRecipients $recipients,
    ) {}

    public function created(Ticket $ticket, ?User $actor = null): int
    {
        return $this->notifier->send(
            'ticket.created',
            $this->recipients->all($ticket, $actor),
            TicketMergeData::for($ticket),
            $actor,
            $this->url($ticket),
        );
    }

    /**
     * A move, told as whichever of the three events it actually is.
     *
     * Resolved and closed are their own events rather than also firing the
     * generic status change: one move must not send a customer two messages,
     * and "your ticket is resolved" is worth its own template and its own row
     * in the matrix — plenty of desks tell the customer about that and nothing
     * else.
     */
    public function moved(Ticket $ticket, TicketStatus $from, TicketStatus $to, ?User $actor = null): int
    {
        $event = match ($to) {
            TicketStatus::Resolved => 'ticket.resolved',
            TicketStatus::Closed => 'ticket.closed',
            default => 'ticket.status_changed',
        };

        return $this->notifier->send(
            $event,
            $this->recipients->all($ticket, $actor),
            TicketMergeData::forMove($ticket, $from, $to),
            $actor,
            $this->url($ticket),
        );
    }

    /**
     * Retriage, told to our side only.
     *
     * `ticket.priority_changed` does not list Customer among its recipient
     * types, so the matrix has no cell to switch on: how urgently we are
     * treating something is our judgement, and "we have downgraded you to low"
     * is not a message anybody means to send.
     */
    public function priorityChanged(Ticket $ticket, TicketPriority $from, TicketPriority $to, ?User $actor = null): int
    {
        return $this->notifier->send(
            'ticket.priority_changed',
            $this->recipients->internal($ticket, $actor),
            TicketMergeData::forPriority($ticket, $from, $to),
            $actor,
            $this->url($ticket),
        );
    }

    /**
     * Handed to somebody.
     *
     * Internal by design: which of us is holding it is not the customer's
     * business, and a customer who hears every reassignment reads it as being
     * passed around.
     */
    public function assigned(Ticket $ticket, ?User $actor = null): int
    {
        return $this->notifier->send(
            'ticket.assigned',
            $this->recipients->internal($ticket, $actor),
            TicketMergeData::for($ticket),
            $actor,
            $this->url($ticket),
        );
    }

    /**
     * Something said on the ticket.
     *
     * An internal comment reaches our side only — the recipient list is built
     * without the customer rather than filtered afterwards, so there is no
     * ordering of conditions in which a private note goes out.
     */
    public function commentAdded(Ticket $ticket, TicketComment $comment, ?User $actor = null): int
    {
        $recipients = $comment->is_internal
            ? $this->recipients->internal($ticket, $actor)
            : $this->recipients->all($ticket, $actor);

        return $this->notifier->send(
            'ticket.comment_added',
            $recipients,
            TicketMergeData::forComment($ticket, $comment),
            $actor,
            $this->url($ticket),
        );
    }

    /**
     * The promise is running out.
     *
     * Our side only: a customer told "we are about to be late" has learned
     * nothing they can act on, and a desk that sent it would be announcing its
     * own failures in advance.
     */
    public function slaWarning(Ticket $ticket, string $kind): int
    {
        return $this->notifier->send(
            'ticket.sla_warning',
            $this->recipients->internal($ticket),
            TicketMergeData::forSla($ticket, $kind),
            // No actor: a clock running out is nobody's action, so there is
            // nobody to leave out of the list.
            null,
            $this->url($ticket),
        );
    }

    /**
     * The promise has been missed.
     *
     * Goes to the agent and the administrators — the brief's two audiences —
     * and to the watchers, who asked to hear about this ticket.
     */
    public function slaBreached(Ticket $ticket, string $kind): int
    {
        return $this->notifier->send(
            'ticket.sla_breached',
            $this->recipients->internal($ticket),
            TicketMergeData::forSla($ticket, $kind),
            null,
            $this->url($ticket),
        );
    }

    private function url(Ticket $ticket): string
    {
        return route('tickets.show', $ticket->id);
    }
}
