<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use App\Domain\Support\TicketNotifications;
use App\Models\User;
use RuntimeException;

/**
 * Adds one thing said on a ticket, and tells whoever should hear it.
 *
 * Both sides of the conversation come through here — an agent's reply and a
 * customer's, the second of which 9.x's inbound email will use. That is why the
 * author is a nullable user and the customer's name is a column: a reply can
 * arrive from somebody who has no account.
 */
class AddTicketCommentAction
{
    public function __construct(
        private readonly TicketNotifications $notifications,
        private readonly RecordFirstResponseAction $recordFirstResponse,
    ) {}

    /**
     * @throws RuntimeException when the comment is empty
     */
    public function __invoke(
        Ticket $ticket,
        string $body,
        ?User $author = null,
        bool $internal = false,
        bool $fromCustomer = false,
        ?string $authorName = null,
    ): TicketComment {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('A reply needs something in it.');
        }

        $comment = new TicketComment;
        $comment->forceFill([
            'ticket_id' => $ticket->id,
            'author_id' => $author?->id,
            'author_name' => $authorName,
            'body' => $body,
            // A customer's own words are never an internal note, whatever was
            // passed: "internal" means "the customer cannot see this", and
            // hiding their own message from them is meaningless.
            'is_internal' => $fromCustomer ? false : $internal,
            'from_customer' => $fromCustomer,
        ])->save();

        // Before the notification, so a message quoting the clock quotes it
        // after this reply rather than before.
        $this->recordFirstResponse->__invoke($ticket, $comment);

        $this->notifications->commentAdded($ticket, $comment, $author);

        return $comment;
    }
}
