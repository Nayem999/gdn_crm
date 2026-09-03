<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateContactAction
{
    public function __construct(private readonly SetPrimaryContactAction $primary) {}

    /**
     * @throws RuntimeException when the chosen account is not a real account
     */
    public function __invoke(ContactData $data, User $actor): Contact
    {
        if ($data->accountId !== null && ! Account::query()->whereKey($data->accountId)->exists()) {
            throw new RuntimeException('That account does not exist.');
        }

        return DB::transaction(function () use ($data, $actor) {
            $attributes = $data->toAttributes();

            // A contact always has an owner; unassigned records are how
            // visibility scoping springs a leak.
            $attributes['owner_id'] = $data->ownerId ?? $actor->id;

            $contact = Contact::create($attributes);

            // The first contact at an account becomes its primary without
            // anyone having to remember, and an explicit request is honoured.
            $shouldBePrimary = $data->isPrimary
                || ($data->accountId !== null && ! $this->accountHasPrimary($data->accountId, $contact));

            $this->primary->apply($contact, $shouldBePrimary);

            return $contact->refresh();
        });
    }

    private function accountHasPrimary(int $accountId, Contact $excluding): bool
    {
        return Contact::query()
            ->where('account_id', $accountId)
            ->whereKeyNot($excluding->getKey())
            ->where('is_primary', true)
            ->exists();
    }
}
