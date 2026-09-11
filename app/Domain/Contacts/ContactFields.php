<?php

namespace App\Domain\Contacts;

use App\Domain\Contacts\Enums\Department;
use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;

/**
 * One place that says what the contacts list can show, sort and filter by.
 *
 * The screen and the export both read it, so a column cannot be filterable on
 * screen and absent from a queued export.
 */
final class ContactFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return CustomFieldColumns::mergeColumns('contacts', [
            // Sorted by surname: "Dana Scully" is not a column, so sorting the
            // name column on first_name would surprise anyone scanning a list.
            new Column('name', 'Name', locked: true, sortColumn: 'last_name'),
            new Column('account', 'Account', sortable: false),
            Column::make('job_title', 'Job title'),
            Column::make('department', 'Department'),
            Column::make('email', 'Email'),
            Column::make('phone', 'Phone'),
            new Column('is_primary', 'Primary'),
            new Column('owner', 'Owner', sortable: false),
            Column::optional('mobile', 'Mobile'),
            Column::optional('city', 'City'),
            Column::optional('country', 'Country'),
            Column::optional('created_at', 'Created'),
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
            FilterField::text('job_title', 'Job title'),
            FilterField::select('department', 'Department', Department::options()),
            FilterField::text('email', 'Email'),
            FilterField::text('phone', 'Phone'),
            FilterField::boolean('is_primary', 'Primary contact'),
            FilterField::text('city', 'City'),
            FilterField::text('country', 'Country'),
            FilterField::date('created_at', 'Created'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        // Custom fields are appended here rather than at each call site,
        // because this class is already the one thing the screen and the export
        // both read — merging them anywhere else is how a field becomes
        // filterable on screen and absent from a queued export.
        return CustomFieldColumns::mergeFilters('contacts', $keyed);
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
                return 'contacts.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'mobile', 'job_title'];
    }
}
