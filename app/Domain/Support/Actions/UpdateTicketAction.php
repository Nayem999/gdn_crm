<?php

namespace App\Domain\Support\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Support\DTOs\TicketData;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketNotifications;
use App\Models\User;
use RuntimeException;

class UpdateTicketAction
{
    public function __construct(
        private readonly TicketNotifications $notifications,
        private readonly ApplySlaPolicyAction $applySla,
    ) {}

    /**
     * @throws RuntimeException when a chosen relation is not a real record
     */
    public function __invoke(Ticket $ticket, TicketData $data, ?User $actor = null): Ticket
    {
        $this->guardRelations($data);

        $priority = $ticket->priority();

        $ticket->update($data->toAttributes());
        $ticket->refresh();

        // Only retriage is announced. An edit to the subject or the customer is
        // a correction, and a desk that sent a message for each of those would
        // train everybody to ignore all of them.
        if ($priority !== $data->priority) {
            // Retriage re-cuts the promise, measured from when the ticket came
            // in — a desk cannot buy itself another four hours by changing a
            // dropdown.
            $this->applySla->__invoke($ticket, $ticket->slaPolicy);
            $ticket->refresh();

            $this->notifications->priorityChanged($ticket, $priority, $data->priority, $actor);
        }

        return $ticket;
    }

    private function guardRelations(TicketData $data): void
    {
        if ($data->contactId !== null && ! Contact::query()->whereKey($data->contactId)->exists()) {
            throw new RuntimeException('That contact does not exist.');
        }

        if ($data->accountId !== null && ! Account::query()->whereKey($data->accountId)->exists()) {
            throw new RuntimeException('That account does not exist.');
        }

        if ($data->contactId === null || $data->accountId === null) {
            return;
        }

        $contact = Contact::query()->whereKey($data->contactId)->first();

        if ($contact !== null && $contact->account_id !== null && $contact->account_id !== $data->accountId) {
            throw new RuntimeException($contact->fullName().' does not work at that account.');
        }
    }
}
