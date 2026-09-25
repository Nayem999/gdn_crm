<?php

namespace App\Domain\Leads;

use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Deals\PipelineModules;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\RequestMemo;
use App\Models\User;

/**
 * One place that says what the leads list can show, sort and filter by.
 *
 * The screen and the export both read it, so a column cannot be filterable on
 * screen and absent from a queued export.
 */
final class LeadFields
{
    /**
     * The computed score, as a field key.
     *
     * A qualification requirement may read it ("score is at least 50"), but a
     * scoring rule may not: a rule that scored on the score would define the
     * score in terms of itself.
     */
    public const SCORE = 'score';

    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return CustomFieldColumns::mergeColumns('leads', [
            // No name column exists, so sort the displayed full name by surname.
            new Column('name', 'Name', locked: true, sortColumn: 'last_name'),
            Column::make('company_name', 'Company'),
            Column::make('status', 'Status'),
            Column::make('source', 'Source'),
            new Column('estimated_value', 'Estimated value', numeric: true),
            new Column('score', 'Score', numeric: true),
            Column::make('email', 'Email'),
            Column::make('phone', 'Phone'),
            new Column('assignees', 'Assignees', sortable: false),
            new Column('lead_owner', 'Lead owner', sortable: false),
            new Column('days_in_status', 'Days in status', sortColumn: 'status_changed_at', numeric: true),
            Column::optional('job_title', 'Job title'),
            Column::optional('city', 'City'),
            Column::optional('country', 'Country'),
            Column::optional('created_at', 'Captured'),
        ]);
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::text('first_name', 'First name'),
            FilterField::text('last_name', 'Last name'),
            FilterField::text('company_name', 'Company'),
            FilterField::select('status', 'Status', PipelineModules::statusOptions('leads')),
            FilterField::select('source', 'Source', LeadSource::options()),
            FilterField::number('estimated_value', 'Estimated value'),
            FilterField::number('score', 'Score'),
            FilterField::text('email', 'Email'),
            FilterField::text('phone', 'Phone'),
            FilterField::text('job_title', 'Job title'),
            FilterField::text('city', 'City'),
            FilterField::text('country', 'Country'),
            FilterField::select('lead_owner_id', 'Lead owner', self::ownerOptions()),
            FilterField::date('status_changed_at', 'Status changed'),
            FilterField::date('created_at', 'Captured'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        // Custom fields are appended here rather than at each call site,
        // because this class is already the one thing the screen and the export
        // both read — merging them anywhere else is how a field becomes
        // filterable on screen and absent from a queued export.
        return CustomFieldColumns::mergeFilters('leads', $keyed);
    }

    /**
     * Everybody a lead could be owned by, looked up once per request: the
     * filter list is rebuilt by the screen, the filter builder, exports,
     * reports and the scoring sweep alike.
     *
     * @return array<int, string>
     */
    private static function ownerOptions(): array
    {
        return app(RequestMemo::class)->remember(
            'lead-fields.owner-options',
            fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all(),
        );
    }

    /**
     * The database column behind a sort key, or null when that key is not one
     * the list sorts by. Nothing from the browser reaches an ORDER BY without
     * passing through here.
     */
    public static function sortColumn(string $key): ?string
    {
        foreach (self::columns() as $column) {
            if ($column->key === $key && $column->sortable) {
                return 'leads.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['first_name', 'last_name', 'company_name', 'email', 'phone', 'mobile'];
    }
}
