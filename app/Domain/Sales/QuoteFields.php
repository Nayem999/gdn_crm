<?php

namespace App\Domain\Sales;

use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;

/**
 * One place that says what the quotes list can show, sort and filter by.
 */
final class QuoteFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return CustomFieldColumns::mergeColumns('quotes', [
            Column::locked('number', 'Number'),
            Column::make('bill_to_name', 'Customer'),
            Column::make('status', 'Status'),
            new Column('total', 'Total', numeric: true),
            Column::make('issue_date', 'Issued'),
            Column::make('valid_until', 'Valid until'),
            new Column('owner', 'Owner', sortable: false),
            Column::optional('version', 'Version'),
            Column::optional('created_at', 'Created'),
        ]);
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::text('number', 'Number'),
            FilterField::text('bill_to_name', 'Customer'),
            FilterField::select('status', 'Status', QuoteStatus::options()),
            FilterField::number('total', 'Total'),
            FilterField::date('issue_date', 'Issued'),
            FilterField::date('valid_until', 'Valid until'),
            FilterField::date('created_at', 'Created'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return CustomFieldColumns::mergeFilters('quotes', $keyed);
    }

    public static function sortColumn(string $key): ?string
    {
        foreach (self::columns() as $column) {
            if ($column->key === $key && $column->sortable) {
                return 'quotes.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['number', 'bill_to_name', 'bill_to_email'];
    }
}
