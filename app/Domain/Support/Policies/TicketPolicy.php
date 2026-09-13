<?php

namespace App\Domain\Support\Policies;

use App\Domain\Support\Models\Ticket;
use App\Models\User;

/**
 * Both questions must pass: does this person hold the permission, and does the
 * ticket fall inside their access level? Visibility uses the same scope the
 * queue does, so a ticket outside it cannot be reached by guessing its id.
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tickets.view');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.view') && $this->isVisibleTo($user, $ticket);
    }

    public function create(User $user): bool
    {
        return $user->can('tickets.create');
    }

    /**
     * Moving a ticket's status sits under update: changing it is the work, and
     * an agent who may not mark their own ticket resolved cannot use the
     * module at all.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.update') && $this->isVisibleTo($user, $ticket);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.delete') && $this->isVisibleTo($user, $ticket);
    }

    /**
     * Handing a ticket to another agent is its own permission, the way it is
     * for leads and deals: moving work between people is a different act from
     * doing it.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->can('tickets.assign') && $this->isVisibleTo($user, $ticket);
    }

    public function export(User $user): bool
    {
        return $user->can('tickets.export');
    }

    private function isVisibleTo(User $user, Ticket $ticket): bool
    {
        return Ticket::query()
            ->visibleTo($user)
            ->whereKey($ticket->getKey())
            ->withTrashed()
            ->exists();
    }
}
