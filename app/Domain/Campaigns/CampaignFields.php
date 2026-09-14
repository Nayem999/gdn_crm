<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\CampaignType;
use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;

/**
 * One place that says what the campaigns list can show, sort and filter by.
 *
 * The screen and the export both read it, so a column can never be exportable
 * but unsortable, or filterable on screen and absent from a queued export.
 */
final class CampaignFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return CustomFieldColumns::mergeColumns('campaigns', [
            Column::locked('name', 'Name'),
            Column::make('status', 'Status'),
            Column::make('type', 'Type'),
            Column::optional('code', 'Code'),
            Column::make('start_date', 'Starts'),
            Column::make('end_date', 'Ends'),
            new Column('budget', 'Budget', numeric: true),
            new Column('actual_cost', 'Spent', numeric: true),
            // Derived from the two above, so there is nothing to sort by on the
            // table — the same arrangement ProductFields has for margin.
            new Column('budget_used', 'Budget used', sortable: false, numeric: true),
            new Column('leads_count', 'Leads', sortable: false, numeric: true),
            new Column('deals_count', 'Deals', sortable: false, numeric: true),
            Column::optional('expected_revenue', 'Expected revenue'),
            new Column('owner', 'Owner', sortable: false),
            Column::optional('created_at', 'Added'),
        ]);
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::text('name', 'Name'),
            FilterField::text('code', 'Code'),
            FilterField::select('status', 'Status', CampaignStatus::options()),
            FilterField::select('type', 'Type', CampaignType::options()),
            FilterField::date('start_date', 'Starts'),
            FilterField::date('end_date', 'Ends'),
            FilterField::number('budget', 'Budget'),
            FilterField::number('actual_cost', 'Spent'),
            FilterField::number('expected_revenue', 'Expected revenue'),
            FilterField::date('created_at', 'Added'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return CustomFieldColumns::mergeFilters('campaigns', $keyed);
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
                return 'campaigns.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['name', 'code', 'description'];
    }
}
