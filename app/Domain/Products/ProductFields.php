<?php

namespace App\Domain\Products;

use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;

/**
 * One place that says what the products list can show, sort and filter by.
 *
 * The screen and the export both read it, so a column can never be exportable
 * but unsortable, or filterable on screen and absent from a queued export.
 */
final class ProductFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return CustomFieldColumns::mergeColumns('products', [
            Column::locked('name', 'Name'),
            Column::make('sku', 'SKU'),
            Column::make('kind', 'Type'),
            Column::make('category', 'Category'),
            new Column('list_price', 'List price', numeric: true),
            // Derived from list price and cost, so there is nothing to sort by
            // on the table — the same arrangement DealFields has for weighted
            // value.
            new Column('margin', 'Margin', sortable: false, numeric: true),
            Column::make('unit', 'Unit'),
            Column::optional('cost_price', 'Cost'),
            Column::optional('tax_rate', 'Tax rate'),
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
            FilterField::text('sku', 'SKU'),
            FilterField::select('kind', 'Type', ProductKind::options()),
            FilterField::select('unit', 'Unit', ProductUnit::options()),
            FilterField::text('category', 'Category'),
            FilterField::number('list_price', 'List price'),
            FilterField::number('cost_price', 'Cost'),
            FilterField::boolean('is_active', 'In the catalogue'),
            FilterField::date('created_at', 'Added'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return CustomFieldColumns::mergeFilters('products', $keyed);
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
                return 'products.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['name', 'sku', 'category', 'description'];
    }
}
