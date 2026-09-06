<?php

namespace App\Domain\Leads;

use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Domain\Shared\Duplicates\FormatsMergeValues;
use App\Domain\Shared\Duplicates\MatchRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How leads are matched and merged.
 *
 * The same person captured twice is the commonest kind of duplicate here — a
 * web form and a phone call an hour apart — so both contact numbers are matched
 * as well as the email.
 */
class LeadDuplicates implements DuplicateSource
{
    use FormatsMergeValues;

    public function key(): string
    {
        return 'leads';
    }

    public function modelClass(): string
    {
        return Lead::class;
    }

    /**
     * @return array<int, MatchRule>
     */
    public function rules(): array
    {
        return [
            MatchRule::email(),
            MatchRule::phone('phone'),
            MatchRule::phone('mobile'),
            MatchRule::company('company_name'),
            MatchRule::personName(['first_name', 'last_name']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function mergeableFields(): array
    {
        return [
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'job_title' => 'Job title',
            'company_name' => 'Company',
            'email' => 'Email',
            'phone' => 'Phone',
            'mobile' => 'Mobile',
            'website' => 'Website',
            'address_line_1' => 'Address line 1',
            'address_line_2' => 'Address line 2',
            'city' => 'City',
            'state' => 'State or region',
            'postal_code' => 'Postal code',
            'country' => 'Country',
            'source' => 'Source',
            'estimated_value' => 'Estimated value',
            'description' => 'Notes',
            'owner_id' => 'Owner',
        ];
    }

    /**
     * Nothing points at a lead yet. Task 2.6's conversion will, and this is
     * where those rows get moved.
     *
     * @return array<int, array{table: string, column: string}>
     */
    public function inboundRelations(): array
    {
        return [];
    }

    /**
     * @return Builder<Lead>
     */
    public function visibleQuery(User $user): Builder
    {
        return Lead::query()->visibleTo($user);
    }

    public function label(Model $record): string
    {
        /** @var Lead $record */
        return $record->fullName();
    }

    public function showRoute(Model $record): string
    {
        return route('leads.show', $record);
    }

    public function displayValue(Model $record, string $field): string
    {
        /** @var Lead $record */
        return match ($field) {
            'owner_id' => $this->ownerName($record->owner_id),
            'estimated_value' => $this->money($record->estimated_value),
            'source' => $record->source()?->label() ?? self::BLANK,
            default => $this->blankOr($record->getAttribute($field)),
        };
    }

    public function afterMerge(Model $survivor, Model $loser): void
    {
        // A lead's status is not a mergeable field, so nothing here can have
        // moved it, and there is nothing else to reconcile.
    }
}
