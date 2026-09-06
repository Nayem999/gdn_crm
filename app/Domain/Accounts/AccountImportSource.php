<?php

namespace App\Domain\Accounts;

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Shared\Imports\HandlesImportFields;
use App\Domain\Shared\Imports\ImportField;
use App\Domain\Shared\Imports\ImportSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * What a file may carry into the account list.
 *
 * `parent_id` is offered because a hierarchy is often what a file is bringing
 * in. CreateAccountAction refuses a parent that does not exist, and the row is
 * reported rather than quietly flattened to the top level.
 */
class AccountImportSource implements ImportSource
{
    use HandlesImportFields;

    public function __construct(private readonly CreateAccountAction $createAccount) {}

    public function key(): string
    {
        return 'accounts';
    }

    public function label(): string
    {
        return 'Accounts';
    }

    public function permission(): string
    {
        return 'accounts.import';
    }

    public function indexRoute(): string
    {
        return route('accounts.index');
    }

    /**
     * @return array<string, ImportField>
     */
    public function fields(): array
    {
        $fields = [
            ImportField::required('name', 'Account name', ['string', 'max:180']),
            ImportField::optional('legal_name', 'Legal name', ['string', 'max:180']),
            ImportField::optional(
                'industry',
                'Industry',
                [Rule::in(array_keys(Industry::options()))],
                'One of: '.implode(', ', array_keys(Industry::options())),
            ),
            ImportField::optional(
                'size',
                'Size',
                [Rule::in(array_keys(AccountSize::options()))],
                'One of: '.implode(', ', array_keys(AccountSize::options())),
            ),
            ImportField::optional('annual_revenue', 'Annual revenue', ['numeric', 'min:0', 'max:9999999999999.99']),
            ImportField::optional('website', 'Website', ['string', 'max:180']),
            ImportField::optional('email', 'Email', ['email', 'max:180']),
            ImportField::optional('phone', 'Phone', ['string', 'max:40']),
            ImportField::optional('address_line_1', 'Address line 1', ['string', 'max:180']),
            ImportField::optional('address_line_2', 'Address line 2', ['string', 'max:180']),
            ImportField::optional('city', 'City', ['string', 'max:120']),
            ImportField::optional('state', 'State or region', ['string', 'max:120']),
            ImportField::optional('postal_code', 'Postal code', ['string', 'max:32']),
            ImportField::optional('country', 'Country', ['string', 'max:120']),
            ImportField::optional(
                'parent_id',
                'Parent account id',
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
        return ($this->createAccount)(AccountData::fromArray($row), $actor);
    }
}
