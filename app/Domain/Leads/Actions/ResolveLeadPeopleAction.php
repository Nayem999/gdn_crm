<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Models\Contact;
use App\Models\User;
use RuntimeException;

/**
 * Turns the organisation and contact-person fields of the lead form and the
 * convert page into records: an account and people either picked from what is
 * on file, or created from their own details — never from the lead's.
 *
 * Callers check visibility first; this only proves a picked id still exists.
 */
class ResolveLeadPeopleAction
{
    public function __construct(
        private readonly CreateAccountAction $createAccount,
        private readonly CreateContactAction $createContact,
    ) {}

    /**
     * The picked account, or one created from the new-account details, or
     * null when neither was given.
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, website?: ?string}|null  $details
     *
     * @throws RuntimeException when the picked account no longer exists
     */
    public function account(?int $accountId, ?array $details, ?int $ownerId, User $actor): ?Account
    {
        if ($accountId !== null) {
            return Account::query()->find($accountId)
                ?? throw new RuntimeException('That account does not exist.');
        }

        $name = $this->clean($details['name'] ?? null);

        if ($name === null) {
            return null;
        }

        return ($this->createAccount)(new AccountData(
            name: $name,
            website: $this->clean($details['website'] ?? null),
            email: $this->clean($details['email'] ?? null),
            phone: $this->clean($details['phone'] ?? null),
            ownerId: $ownerId,
        ), $actor);
    }

    /**
     * One contact per row, in order: the picked person, or a new one created
     * from the row's own details. A row with neither is skipped.
     *
     * New people join $accountId. With $adoptExisting, picked people who belong
     * to no account join it too — what conversion wants, and the lead form
     * does not presume.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, Contact>
     *
     * @throws RuntimeException when a picked contact no longer exists
     */
    public function contacts(array $rows, ?int $accountId, ?int $ownerId, User $actor, bool $adoptExisting = false): array
    {
        $contacts = [];

        foreach ($rows as $row) {
            $contactId = is_numeric($row['contact_id'] ?? null) ? (int) $row['contact_id'] : null;

            if ($contactId !== null) {
                $contact = Contact::query()->find($contactId)
                    ?? throw new RuntimeException('That contact does not exist.');

                if ($adoptExisting && $accountId !== null && $contact->account_id === null) {
                    $contact->forceFill(['account_id' => $accountId])->save();
                }

                $contacts[$contact->id] = $contact;

                continue;
            }

            $firstName = $this->clean($row['first_name'] ?? null);
            $lastName = $this->clean($row['last_name'] ?? null);

            if ($firstName === null || $lastName === null) {
                continue;
            }

            $contact = ($this->createContact)(new ContactData(
                firstName: $firstName,
                lastName: $lastName,
                jobTitle: $this->clean($row['job_title'] ?? null),
                email: $this->clean($row['email'] ?? null),
                phone: $this->clean($row['phone'] ?? null),
                mobile: $this->clean($row['mobile'] ?? null),
                addressLine1: $this->clean($row['address_line_1'] ?? null),
                addressLine2: $this->clean($row['address_line_2'] ?? null),
                city: $this->clean($row['city'] ?? null),
                state: $this->clean($row['state'] ?? null),
                postalCode: $this->clean($row['postal_code'] ?? null),
                country: $this->clean($row['country'] ?? null),
                accountId: $accountId,
                ownerId: $ownerId,
            ), $actor);

            $contacts[$contact->id] = $contact;
        }

        return array_values($contacts);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
