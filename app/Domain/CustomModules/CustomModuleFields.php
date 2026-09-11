<?php

namespace App\Domain\CustomModules;

use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\CustomModules\Models\CustomModule;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;
use App\Models\User;

/**
 * What a generated module's list can show, sort and filter by.
 *
 * The same shape every built-in module's `{Module}Fields` class has, and read
 * by both the screen and the export for the same reason — so a field cannot be
 * filterable on screen and missing from a queued export.
 *
 * A generated module has exactly three columns of its own: the title, the
 * owner, and when it was created. Everything else is a 4.1 custom field, merged
 * in here by the same call the built-in modules make.
 */
final class CustomModuleFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(CustomModule $module): array
    {
        return CustomFieldColumns::mergeColumns($module->moduleKey(), [
            new Column('name', $module->title_label, locked: true),
            new Column('owner', 'Owner', sortable: false),
            Column::optional('created_at', 'Created'),
        ]);
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(CustomModule $module): array
    {
        return CustomFieldColumns::mergeFilters($module->moduleKey(), [
            'name' => FilterField::text('name', $module->title_label),
            'owner_id' => FilterField::select('owner_id', 'Owner', self::ownerOptions()),
            'created_at' => FilterField::date('created_at', 'Created'),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        // The title only. A generated module's other fields live in another
        // table, and reaching into them here would make a queued export match
        // rows the list never showed — see .ai/rules/accounts.md.
        return ['name'];
    }

    /**
     * The column a sort key maps to, or null when it is not sortable.
     */
    public static function sortColumn(string $key): ?string
    {
        return match ($key) {
            'name' => 'custom_records.name',
            'created_at' => 'custom_records.created_at',
            default => null,
        };
    }

    /**
     * Keyed by user id, which is an int — see .ai/rules/settings.md on why a
     * numeric key never stays a string.
     *
     * @return array<int, string>
     */
    public static function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
