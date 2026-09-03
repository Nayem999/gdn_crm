<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Models\Contact;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpdateContactAction
{
    public function __construct(private readonly SetPrimaryContactAction $primary) {}

    /**
     * @throws RuntimeException when the chosen account is not a real account
     */
    public function __invoke(Contact $contact, ContactData $data): Contact
    {
        if ($data->accountId !== null && ! Account::query()->whereKey($data->accountId)->exists()) {
            throw new RuntimeException('That account does not exist.');
        }

        $previousAccountId = $contact->account_id;

        return DB::transaction(function () use ($contact, $data, $previousAccountId) {
            $attributes = $data->toAttributes();

            // The owner only moves when one was chosen, so an edit that leaves
            // the field alone cannot silently unassign the record.
            if ($data->ownerId !== null) {
                $attributes['owner_id'] = $data->ownerId;
            }

            $contact->update($attributes);

            // Moving to a different account drops the old primary flag: being
            // primary somewhere else says nothing about the new account.
            if ($previousAccountId !== $contact->account_id) {
                $this->primary->demote($contact);
                $this->primary->backfill($previousAccountId);
            }

            $this->primary->apply(
                $contact,
                $data->isPrimary
                    || ($contact->account_id !== null && ! $this->accountHasPrimary($contact))
            );

            return $contact->refresh();
        });
    }

    private function accountHasPrimary(Contact $contact): bool
    {
        return Contact::query()
            ->where('account_id', $contact->account_id)
            ->whereKeyNot($contact->getKey())
            ->where('is_primary', true)
            ->exists();
    }
}
