<?php

namespace App\Domain\Contacts;

use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Enums\Department;
use App\Domain\Shared\Imports\HandlesImportFields;
use App\Domain\Shared\Imports\ImportField;
use App\Domain\Shared\Imports\ImportSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * What a file may carry into the contact list.
 *
 * `is_primary` is absent: SetPrimaryContactAction owns that flag, and
 * CreateContactAction already makes the first person at an account primary. A
 * file cannot hand two people the same flag by accident.
 */
class ContactImportSource implements ImportSource
{
    use HandlesImportFields;

    public function __construct(private readonly CreateContactAction $createContact) {}

    public function key(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return 'Contacts';
    }

    public function permission(): string
    {
        return 'contacts.import';
    }

    public function indexRoute(): string
    {
        return route('contacts.index');
    }

    /**
     * @return array<string, ImportField>
     */
    public function fields(): array
    {
        $fields = [
            ImportField::required('first_name', 'First name', ['string', 'max:120']),
            ImportField::required('last_name', 'Last name', ['string', 'max:120']),
            ImportField::optional('job_title', 'Job title', ['string', 'max:120']),
            ImportField::optional(
                'department',
                'Department',
                [Rule::in(array_keys(Department::options()))],
                'One of: '.implode(', ', array_keys(Department::options())),
            ),
            ImportField::optional('email', 'Email', ['email', 'max:180']),
            ImportField::optional('phone', 'Phone', ['string', 'max:40']),
            ImportField::optional('mobile', 'Mobile', ['string', 'max:40']),
            ImportField::optional('address_line_1', 'Address line 1', ['string', 'max:180']),
            ImportField::optional('address_line_2', 'Address line 2', ['string', 'max:180']),
            ImportField::optional('city', 'City', ['string', 'max:120']),
            ImportField::optional('state', 'State or region', ['string', 'max:120']),
            ImportField::optional('postal_code', 'Postal code', ['string', 'max:32']),
            ImportField::optional('country', 'Country', ['string', 'max:120']),
            ImportField::optional(
                'account_id',
                'Account id',
                ['integer', 'exists:accounts,id'],
                'The numeric id of an existing account.',
            ),
            ImportField::optional('description', 'Notes', ['string', 'max:5000']),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    public function create(array $row, User $actor): Model
    {
        return ($this->createContact)(ContactData::fromArray($row), $actor);
    }
}
