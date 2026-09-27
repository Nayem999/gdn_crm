<?php

namespace App\Domain\Leads\Concerns;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;

/**
 * The organisation and contact-person fields shared by the lead form and the
 * convert page: link an account and any number of people on file, or describe
 * new ones on their own fields. Rendered by <x-lead-people :form="$this" />.
 *
 * Creating the records is ResolveLeadPeopleAction's job; this holds the input,
 * validates it, and refuses what the viewer may not do.
 */
trait EditsLeadPeople
{
    use PicksAccountAndContact;

    /**
     * The account on file, if one is picked.
     */
    public ?string $account_id = null;

    /**
     * A new account, used when none is picked and it has a name.
     */
    public string $new_account_name = '';

    public string $new_account_email = '';

    public string $new_account_phone = '';

    public string $new_account_website = '';

    /**
     * One row per contact person: somebody on file (contact_id), or a new
     * person described on the row. An empty row is ignored.
     *
     * @var array<int, array<string, ?string>>
     */
    public array $contacts = [];

    public function addContactRow(): void
    {
        $this->contacts[] = $this->blankContactRow();
    }

    public function removeContactRow(int $index): void
    {
        unset($this->contacts[$index]);
        $this->contacts = array_values($this->contacts);

        // Always one row to fill in, even after removing the last.
        if ($this->contacts === []) {
            $this->contacts = [$this->blankContactRow()];
        }
    }

    public function canCreateAccount(): bool
    {
        return $this->pickerViewer()->can('create', Account::class);
    }

    public function canCreateContact(): bool
    {
        return $this->pickerViewer()->can('create', Contact::class);
    }

    /**
     * Picking somebody on file whose account is known picks that account too,
     * when none is chosen yet. Nothing else on the form is touched.
     */
    public function updatedContacts(mixed $value, string $key): void
    {
        if (! str_ends_with($key, '.contact_id') || $this->isFilled($this->account_id)) {
            return;
        }

        $contact = $this->visibleContact(is_scalar($value) ? (string) $value : null);

        if ($contact?->account_id !== null) {
            $this->account_id = (string) $contact->account_id;
        }
    }

    /**
     * @param  array<string, ?string>  $values
     * @return array<string, ?string>
     */
    protected function blankContactRow(array $values = []): array
    {
        return [
            'contact_id' => null,
            'first_name' => '',
            'last_name' => '',
            'job_title' => '',
            'email' => '',
            'phone' => '',
            'mobile' => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'city' => '',
            'state' => '',
            'postal_code' => '',
            'country' => '',
            ...$values,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function peopleRules(): array
    {
        return [
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'new_account_name' => ['nullable', 'string', 'max:255'],
            'new_account_email' => ['nullable', 'email', 'max:255'],
            'new_account_phone' => ['nullable', 'string', 'max:50'],
            'new_account_website' => ['nullable', 'string', 'max:255'],
            'contacts' => ['array'],
            'contacts.*.contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'contacts.*.first_name' => ['nullable', 'string', 'max:100'],
            'contacts.*.last_name' => ['nullable', 'string', 'max:100'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.mobile' => ['nullable', 'string', 'max:50'],
            'contacts.*.address_line_1' => ['nullable', 'string', 'max:255'],
            'contacts.*.address_line_2' => ['nullable', 'string', 'max:255'],
            'contacts.*.city' => ['nullable', 'string', 'max:100'],
            'contacts.*.state' => ['nullable', 'string', 'max:100'],
            'contacts.*.postal_code' => ['nullable', 'string', 'max:20'],
            'contacts.*.country' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function peopleAttributes(): array
    {
        return [
            'account_id' => 'account',
            'new_account_name' => 'account name',
            'new_account_email' => 'account email',
            'new_account_phone' => 'account phone',
            'new_account_website' => 'account website',
            'contacts.*.contact_id' => 'contact',
            'contacts.*.first_name' => 'first name',
            'contacts.*.last_name' => 'last name',
            'contacts.*.job_title' => 'job title',
            'contacts.*.email' => 'email',
            'contacts.*.phone' => 'phone',
            'contacts.*.mobile' => 'mobile',
            'contacts.*.address_line_1' => 'address',
            'contacts.*.address_line_2' => 'address line 2',
            'contacts.*.city' => 'city',
            'contacts.*.state' => 'state or region',
            'contacts.*.postal_code' => 'postal code',
            'contacts.*.country' => 'country',
        ];
    }

    /**
     * Refuse what cannot be saved, with the error on the field at fault.
     *
     * `exists` proves a picked record is real, not that this person may link
     * to it, so newly picked ids are read back through visibleTo(). Links the
     * record already carries ($keptAccountId, $keptContactIds) stand either
     * way, so editing some other field never fails over somebody else's choice.
     *
     * @param  array<int, int>  $keptContactIds
     */
    protected function guardPeople(?int $keptAccountId = null, array $keptContactIds = [], bool $needsAccount = false, bool $needsPerson = false): bool
    {
        if ($this->isFilled($this->account_id)) {
            if ((int) $this->account_id !== $keptAccountId && $this->visibleAccount($this->account_id) === null) {
                return $this->refuse('account_id', 'That account is not available to you.');
            }
        } elseif ($needsAccount || $this->anyFilled([$this->new_account_name, $this->new_account_email, $this->new_account_phone, $this->new_account_website])) {
            if (! $this->isFilled($this->new_account_name)) {
                return $this->refuse('new_account_name', $needsAccount ? 'Pick an account, or name a new one.' : 'Give the new account a name.');
            }

            if (! $this->canCreateAccount()) {
                return $this->refuse('new_account_name', 'You cannot create accounts.');
            }
        }

        $people = 0;

        foreach ($this->contacts as $index => $row) {
            if ($this->isFilled($row['contact_id'] ?? null)) {
                if (! in_array((int) $row['contact_id'], $keptContactIds, true) && $this->visibleContact($row['contact_id']) === null) {
                    return $this->refuse("contacts.{$index}.contact_id", 'That contact is not available to you.');
                }

                $people++;

                continue;
            }

            if (! $this->anyFilled(array_values(array_diff_key($row, ['contact_id' => true])))) {
                continue;
            }

            if (! $this->canCreateContact()) {
                return $this->refuse("contacts.{$index}.first_name", 'You cannot create contacts.');
            }

            foreach (['first_name' => 'first', 'last_name' => 'last'] as $field => $word) {
                if (! $this->isFilled($row[$field] ?? null)) {
                    return $this->refuse("contacts.{$index}.{$field}", "Give the new person a {$word} name.");
                }
            }

            $people++;
        }

        if ($needsPerson && $people === 0) {
            return $this->refuse('contacts.0.first_name', 'Pick a contact person, or add a new one.');
        }

        return true;
    }

    /**
     * @return array{name: string, email: string, phone: string, website: string}|null
     */
    protected function newAccountDetails(): ?array
    {
        if ($this->isFilled($this->account_id) || ! $this->isFilled($this->new_account_name)) {
            return null;
        }

        return [
            'name' => $this->new_account_name,
            'email' => $this->new_account_email,
            'phone' => $this->new_account_phone,
            'website' => $this->new_account_website,
        ];
    }

    protected function pickedAccountId(): ?int
    {
        return $this->isFilled($this->account_id) ? (int) $this->account_id : null;
    }

    /**
     * The picker options for the account and for each person row.
     *
     * @param  array<int, int>  $suggestedAccounts
     * @param  array<int, int>  $suggestedContacts
     * @return array{accounts: array<int, array{value: string, label: string, description: ?string}>, contacts: array<int, array<int, array{value: string, label: string, description: ?string}>>}
     */
    public function peopleOptions(array $suggestedAccounts = [], array $suggestedContacts = []): array
    {
        return [
            'accounts' => $this->accountPickerOptions($this->account_id, $suggestedAccounts),
            'contacts' => array_map(
                fn (array $row): array => $this->contactPickerOptions($row['contact_id'] ?? null, $suggestedContacts),
                $this->contacts,
            ),
        ];
    }

    protected function isFilled(?string $value): bool
    {
        return trim((string) $value) !== '';
    }

    /**
     * @param  array<int, ?string>  $values
     */
    private function anyFilled(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->isFilled($value)) {
                return true;
            }
        }

        return false;
    }

    private function refuse(string $field, string $message): bool
    {
        $this->addError($field, $message);

        return false;
    }
}
