<?php

namespace App\Domain\Activities;

use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Deals\PipelineModules;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;
use App\Models\User;

/**
 * One place that says what the activities list can show, sort and filter by.
 *
 * The screen and the export both read it, so a column can never be filterable
 * on screen and not in a queued export.
 */
final class ActivityFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return CustomFieldColumns::mergeColumns('activities', [
            Column::locked('subject', 'Activity'),
            Column::make('type', 'Type'),
            Column::make('due_at', 'Due'),
            // Sortable because the column stores a rank, not a name — see the
            // note on ActivityPriority.
            Column::make('priority', 'Priority'),
            Column::make('status', 'Status'),
            // A morph pair cannot be sorted or joined in one column.
            new Column('related', 'About', sortable: false),
            new Column('owner', 'Owner', sortable: false),
            new Column('recurrence', 'Repeats', sortable: false, hiddenByDefault: true),
            new Column('duration_minutes', 'Duration', hiddenByDefault: true, numeric: true),
            new Column('location', 'Location', hiddenByDefault: true),
            Column::optional('completed_at', 'Completed'),
            Column::optional('created_at', 'Created'),
        ]);
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::text('subject', 'Subject'),
            FilterField::select('type', 'Type', ActivityType::options()),
            FilterField::select('status', 'Status', PipelineModules::statusOptions('activities')),
            FilterField::select('priority', 'Priority', ActivityPriority::options()),
            FilterField::date('due_at', 'Due'),
            FilterField::boolean('all_day', 'All day'),
            FilterField::select('owner_id', 'Owner', self::ownerOptions()),
            FilterField::select('related_type', 'About', self::relatedTypeOptions()),
            FilterField::text('location', 'Location'),
            FilterField::date('completed_at', 'Completed'),
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
        return CustomFieldColumns::mergeFilters('activities', $keyed);
    }

    /**
     * Keyed by user id, which is an int — PHP will not hold "7" as a string key,
     * so there is nothing to be gained by casting it to one.
     *
     * @return array<int, string>
     */
    public static function ownerOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Keyed by the stored morph value so the filter compares against what is in
     * the column, while the label stays the module's own word for it.
     *
     * @return array<string, string>
     */
    public static function relatedTypeOptions(): array
    {
        $options = [];

        foreach (ActivityRelations::options() as $module => $label) {
            $morph = ActivityRelations::morphClass($module);

            if ($morph !== null) {
                $options[$morph] = $label;
            }
        }

        return $options;
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
                return 'activities.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['subject', 'description', 'location'];
    }
}
