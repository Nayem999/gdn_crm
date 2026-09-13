<?php

namespace App\Domain\Support\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Support\DTOs\TicketData;
use App\Domain\Support\Models\Ticket;
use RuntimeException;

class UpdateTicketAction
{
    /**
     * @throws RuntimeException when a chosen relation is not a real record
     */
    public function __invoke(Ticket $ticket, TicketData $data): Ticket
    {
        $this->guardRelations($data);

        $ticket->update($data->toAttributes());

        return $ticket->refresh();
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
