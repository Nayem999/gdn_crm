<?php

namespace App\Domain\CustomFields;

use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\Models\CustomFieldValue;
use App\Domain\Settings\DisplayTime;
use App\Domain\Settings\NumberFormat;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Turns field definitions into the shapes the rest of the application already
 * understands: a data-view Column, a FilterField, an export cell.
 *
 * This is what makes a new custom field appear on the list, in the column
 * manager, in the filter builder and in an export **without anybody adding it
 * to four places**. Each module's `{Module}Fields` class merges these in, and
 * that class is already the single thing both the screen and the export read.
 */
final class CustomFieldColumns
{
    /**
     * Custom column keys are prefixed so they cannot collide with a real
     * column. A field keyed `status` would otherwise shadow the module's own
     * status in the column manager, the sort and the export headings.
     */
    public const PREFIX = 'cf_';

    public static function columnKey(CustomField $field): string
    {
        return self::PREFIX.$field->key;
    }

    /**
     * The field key behind a column key, or null when this is not one of ours.
     */
    public static function fieldKey(string $columnKey): ?string
    {
        return str_starts_with($columnKey, self::PREFIX)
            ? substr($columnKey, strlen(self::PREFIX))
            : null;
    }

    public static function isCustom(string $columnKey): bool
    {
        return self::fieldKey($columnKey) !== null;
    }

    /**
     * @return array<int, Column>
     */
    public static function columns(string $module): array
    {
        $columns = [];

        foreach (self::schema()->forModule($module) as $field) {
            $type = $field->type();

            $columns[] = new Column(
                key: self::columnKey($field),
                label: $field->label,
                // Not sortable, on purpose. The value lives in another table,
                // so ordering by it needs a join the kit's sort does not carry
                // — and a sort control that silently ordered by nothing is
                // worse than no sort control. Same call `weighted_value` makes.
                sortable: false,
                // Off until somebody turns them on: a module with a dozen
                // custom fields would otherwise push its own columns off the
                // screen the moment they were defined.
                hiddenByDefault: true,
                numeric: $type === CustomFieldType::Number || $type === CustomFieldType::Currency,
            );
        }

        return $columns;
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(string $module): array
    {
        $filters = [];

        foreach (self::schema()->forModule($module) as $field) {
            $key = self::columnKey($field);
            $type = $field->type();

            $filters[$key] = new FilterField(
                key: $key,
                label: $field->label,
                type: $type->filterType(),
                options: $type->hasOptions() ? $field->optionMap() : [],
                customFieldId: $field->id,
                customFieldColumn: $type->column(),
                customFieldIsList: $type->isMultiple(),
            );
        }

        return $filters;
    }

    /**
     * Both lists at once, merged onto a module's own declarations.
     *
     * @param  array<int, Column>  $base
     * @return array<int, Column>
     */
    public static function mergeColumns(string $module, array $base): array
    {
        return [...$base, ...self::columns($module)];
    }

    /**
     * @param  array<string, FilterField>  $base
     * @return array<string, FilterField>
     */
    public static function mergeFilters(string $module, array $base): array
    {
        return [...$base, ...self::filters($module)];
    }

    /**
     * What a custom field's answer says, written for a person.
     *
     * Returns null when the key is not a custom column at all, which is how the
     * data view and the export tell "no answer" from "not mine".
     */
    public static function display(Model $record, string $columnKey): ?string
    {
        $field = self::fieldFor($record, $columnKey);

        if ($field === null) {
            return null;
        }

        $value = self::rawValue($record, $field);

        if ($value === null || $value === []) {
            return '';
        }

        return match ($field->type()) {
            CustomFieldType::Checkbox => $value ? 'Yes' : 'No',
            CustomFieldType::Currency, CustomFieldType::Number => NumberFormat::format((float) $value, 2),
            CustomFieldType::Date => DisplayTime::date(Carbon::parse((string) $value)),
            CustomFieldType::Select => (string) $field->optionLabel((string) $value),
            CustomFieldType::MultiSelect => implode(', ', array_map(
                static fn (string $key): string => (string) $field->optionLabel($key),
                is_array($value) ? $value : [],
            )),
            CustomFieldType::Lookup => self::lookupLabel($field, (int) $value),
            default => (string) $value,
        };
    }

    /**
     * The value an export cell should carry.
     *
     * A number goes out as a **number**, not a formatted string — a
     * spreadsheet column of "1,200.00" cannot be summed, which is the same call
     * the deals export makes for weighted value. Everything else goes out as
     * the label a person would read, because an export of option keys is an
     * export nobody can use.
     */
    public static function exportValue(Model $record, string $columnKey): string|float|null
    {
        $field = self::fieldFor($record, $columnKey);

        if ($field === null) {
            return null;
        }

        $type = $field->type();

        if ($type === CustomFieldType::Number || $type === CustomFieldType::Currency) {
            $value = self::rawValue($record, $field);

            return $value === null ? null : (float) $value;
        }

        $display = self::display($record, $columnKey);

        return $display === '' ? null : $display;
    }

    /**
     * The definition behind a column key, for the module this record belongs
     * to. Null when the key is not a custom column, or names no active field.
     */
    public static function fieldFor(Model $record, string $columnKey): ?CustomField
    {
        $fieldKey = self::fieldKey($columnKey);
        $module = CustomFieldRegistry::keyFor($record);

        if ($fieldKey === null || $module === null) {
            return null;
        }

        return self::schema()->find($module, $fieldKey);
    }

    /**
     * The stored answer, read off the eager-loaded relation where there is one.
     *
     * Reading through the loaded collection rather than querying keeps a list
     * of fifty records to one query for their values, instead of one per cell.
     * `WithDataView` and the export both eager-load `customFieldValues`.
     *
     * @return string|float|bool|array<int, string>|int|null
     */
    private static function rawValue(Model $record, CustomField $field): string|float|bool|array|int|null
    {
        if (! method_exists($record, 'customFieldValues')) {
            return null;
        }

        // getRelationValue rather than the property: it returns the loaded
        // relation when there is one and loads it otherwise, and it is a method
        // every Model has — so this does not depend on the concrete class
        // declaring the relation as a property.
        $rows = $record->getRelationValue('customFieldValues');

        if (! $rows instanceof Collection) {
            return null;
        }

        $row = $rows->firstWhere('custom_field_id', $field->id);

        return $row instanceof CustomFieldValue ? $row->value($field->type()) : null;
    }

    private static function lookupLabel(CustomField $field, int $id): string
    {
        $module = (string) $field->lookup_module;
        $class = CustomFieldRegistry::modelClass($module);

        if ($class === null) {
            return '#'.$id;
        }

        // Read without a visibility scope on purpose: this renders a value the
        // record already holds, and blanking it for one reader and not another
        // would make the same row look different to two people. What somebody
        // may *choose* is scoped — see CustomFieldValidator::lookupErrors.
        $related = $class::query()->find($id);

        return $related === null ? '#'.$id : CustomFieldRegistry::recordLabel($related);
    }

    private static function schema(): CustomFieldSchema
    {
        return app(CustomFieldSchema::class);
    }
}
