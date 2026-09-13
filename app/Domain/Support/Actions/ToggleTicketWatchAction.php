<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\Ticket;
use App\Models\User;

/**
 * Starts or stops somebody following a ticket.
 *
 * Watching is a personal choice, not an assignment, so it needs no permission
 * beyond being able to see the ticket — which the caller has already
 * established. There is nothing to notify about: nobody needs telling that a
 * colleague is reading.
 */
class ToggleTicketWatchAction
{
    /**
     * @return bool whether they are now watching
     */
    public function __invoke(Ticket $ticket, User $user): bool
    {
        // detach() returns how many rows went, so this asks and acts in one
        // statement rather than reading first and racing a second click.
        if ($ticket->watchers()->detach($user->id) > 0) {
            return false;
        }

        // syncWithoutDetaching rather than attach: the unique index would
        // otherwise turn a double-click into an integrity error.
        $ticket->watchers()->syncWithoutDetaching([$user->id]);

        return true;
    }
}
