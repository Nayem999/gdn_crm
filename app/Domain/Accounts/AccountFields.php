<?php

namespace App\Domain\Accounts;

use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;

/**
 * One place that says what the accounts list can show, sort and filter by.
 *
 * The screen and the export both read it, so a column can never be exportable
 * but unsortable, or filterable on screen and not in a queued export.
 */
final class AccountFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return [
            Column::locked('name', 'Name'),
            Column::make('industry', 'Industry'),
            Column::make('size', 'Size'),
            new Column('annual_revenue', 'Annual revenue', numeric: true),
            Column::make('city', 'City'),
            Column::make('country', 'Country'),
            new Column('owner', 'Owner', sortable: false),
            new Column('parent', 'Parent account', sortable: false),
            Column::optional('email', 'Email'),
            Column::optional('phone', 'Phone'),
            Column::optional('website', 'Website'),
            Column::optional('created_at', 'Created'),
        ];
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::text('name', 'Name'),
            FilterField::select('industry', 'Industry', Industry::options()),
            FilterField::select('size', 'Size', AccountSize::options()),
            FilterField::number('annual_revenue', 'Annual revenue'),
            FilterField::text('city', 'City'),
            FilterField::text('country', 'Country'),
            FilterField::text('email', 'Email'),
            FilterField::text('phone', 'Phone'),
            FilterField::date('created_at', 'Created'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
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
                return 'accounts.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['name', 'legal_name', 'email', 'phone', 'city'];
    }
}
