<?php

namespace App\Domain\Support;

use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Models\User;

/**
 * One place that says what the ticket queue can show, sort and filter by.
 *
 * The screen and the export both read it, so a column cannot be filterable on
 * screen and absent from a queued export.
 */
final class TicketFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return CustomFieldColumns::mergeColumns('tickets', [
            Column::locked('subject', 'Ticket'),
            Column::make('number', 'Reference'),
            Column::make('status', 'Status'),
            // Sortable because the column stores a rank, not a name.
            Column::make('priority', 'Priority'),
            new Column('contact', 'Contact', sortable: false),
            new Column('account', 'Account', sortable: false),
            new Column('owner', 'Agent', sortable: false),
            Column::make('created_at', 'Raised'),
            new Column('age', 'Age', sortable: false, numeric: true),
            // Not sortable for the reason age is not: it is measured
            // against a clock that may be paused, which SQL does not know.
            new Column('sla', 'SLA', sortable: false),
            Column::optional('source', 'Came in by'),
            Column::optional('resolved_at', 'Resolved'),
            Column::optional('closed_at', 'Closed'),
        ]);
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::text('subject', 'Subject'),
            FilterField::text('number', 'Reference'),
            FilterField::select('status', 'Status', TicketStatus::options()),
            FilterField::select('priority', 'Priority', TicketPriority::options()),
            FilterField::select('source', 'Came in by', TicketSource::options()),
            FilterField::select('owner_id', 'Agent', self::agentOptions()),
            FilterField::date('created_at', 'Raised'),
            FilterField::date('resolved_at', 'Resolved'),
            FilterField::date('closed_at', 'Closed'),
            FilterField::date('resolution_due_at', 'Resolution due'),
            FilterField::date('resolution_breached_at', 'Resolution breached'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        // Custom fields are appended here rather than at each call site,
        // because this class is already the one thing the screen and the export
        // both read — merging them anywhere else is how a field becomes
        // filterable on screen and absent from a queued export.
        return CustomFieldColumns::mergeFilters('tickets', $keyed);
    }

    /**
     * @return array<string, string>
     */
    public static function agentOptions(): array
    {
        /** @var array<string, string> $options */
        $options = User::query()->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => [(string) $id => $name])
            ->all();

        return $options;
    }

    /**
     * The database column behind a sort key, or null when that key is not one
     * the list sorts by. Nothing from the browser reaches an ORDER BY without
     * passing through here.
     *
     * `age` is deliberately absent: it is measured from `created_at` to either
     * the resolution or to now, so sorting it in SQL would need a CASE the
     * query does not carry and would disagree with the figure on screen for
     * every open ticket.
     */
    public static function sortColumn(string $key): ?string
    {
        foreach (self::columns() as $column) {
            if ($column->key === $key && $column->sortable) {
                return 'tickets.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['number', 'subject', 'description'];
    }
}
