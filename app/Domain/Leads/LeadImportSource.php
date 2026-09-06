<?php

namespace App\Domain\Leads;

use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Shared\Imports\HandlesImportFields;
use App\Domain\Shared\Imports\ImportField;
use App\Domain\Shared\Imports\ImportSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * What a file may carry into the lead database.
 *
 * Status is absent on purpose: an imported lead is New like any other, and
 * ChangeLeadStatusAction owns every move after that. Score is absent for the
 * same kind of reason — it is computed, not supplied.
 */
class LeadImportSource implements ImportSource
{
    use HandlesImportFields;

    public function __construct(private readonly CreateLeadAction $createLead) {}

    public function key(): string
    {
        return 'leads';
    }

    public function label(): string
    {
        return 'Leads';
    }

    public function permission(): string
    {
        return 'leads.import';
    }

    public function indexRoute(): string
    {
        return route('leads.index');
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
            ImportField::optional('company_name', 'Company', ['string', 'max:180']),
            ImportField::optional('email', 'Email', ['email', 'max:180']),
            ImportField::optional('phone', 'Phone', ['string', 'max:40']),
            ImportField::optional('mobile', 'Mobile', ['string', 'max:40']),
            ImportField::optional('website', 'Website', ['string', 'max:180']),
            ImportField::optional('address_line_1', 'Address line 1', ['string', 'max:180']),
            ImportField::optional('address_line_2', 'Address line 2', ['string', 'max:180']),
            ImportField::optional('city', 'City', ['string', 'max:120']),
            ImportField::optional('state', 'State or region', ['string', 'max:120']),
            ImportField::optional('postal_code', 'Postal code', ['string', 'max:32']),
            ImportField::optional('country', 'Country', ['string', 'max:120']),
            ImportField::optional(
                'source',
                'Source',
                [Rule::in(array_keys(LeadSource::options()))],
                'One of: '.implode(', ', array_keys(LeadSource::options())),
            ),
            ImportField::optional('estimated_value', 'Estimated value', ['numeric', 'min:0', 'max:9999999999999.99']),
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
        return ($this->createLead)(LeadData::fromArray($row), $actor);
    }
}
