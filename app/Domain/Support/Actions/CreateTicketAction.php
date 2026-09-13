<?php

namespace App\Domain\Support\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Support\DTOs\TicketData;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use RuntimeException;

class CreateTicketAction
{
    /**
     * @throws RuntimeException when a chosen relation is not a real record
     */
    public function __invoke(TicketData $data, User $actor): Ticket
    {
        $attributes = $data->toAttributes();

        // A ticket always has an agent; an unassigned one is how a support
        // queue quietly loses somebody's problem.
        $attributes['owner_id'] = $data->ownerId > 0 ? $data->ownerId : $actor->id;
        $attributes['status'] = TicketStatus::New->value;

        $this->guardRelations($data);

        // forceFill, not create(): `status` is deliberately out of $fillable so
        // no form can write it, and create() would silently drop the opening
        // status set above. The attribute set here is TicketData's own fixed
        // list plus what this action decided, so nothing from a request reaches
        // a column it should not.
        $ticket = new Ticket;
        $ticket->forceFill($attributes)->save();

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

        // A ticket is raised by a person at an organisation, so a contact from
        // a different account is a mistake worth catching rather than storing —
        // it would put the ticket on the wrong customer's history.
        if ($contact !== null && $contact->account_id !== null && $contact->account_id !== $data->accountId) {
            throw new RuntimeException($contact->fullName().' does not work at that account.');
        }
    }
}
