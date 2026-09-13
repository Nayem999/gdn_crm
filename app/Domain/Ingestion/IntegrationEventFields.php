<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Filters\FilterField;

/**
 * One place that says what the delivery log can show, sort and filter by.
 *
 * The screen and the export both read it, so a column cannot be filterable on
 * screen and absent from a queued export.
 */
final class IntegrationEventFields
{
    /**
     * @return array<int, Column>
     */
    public static function columns(): array
    {
        return [
            Column::locked('received_at', 'Received'),
            new Column('source', 'Source', sortable: false),
            Column::make('status', 'Status'),
            Column::make('outcome', 'Outcome'),
            // A morph pair cannot be sorted or joined in one column.
            new Column('record', 'Made', sortable: false),
            Column::make('external_id', 'Their id'),
            new Column('error', 'Why it failed', sortable: false, hiddenByDefault: true),
            new Column('payload_size', 'Size', sortable: false, hiddenByDefault: true, numeric: true),
            Column::optional('ip_address', 'From'),
            Column::optional('attempts', 'Attempts'),
            Column::optional('processed_at', 'Processed'),
        ];
    }

    /**
     * @return array<string, FilterField>
     */
    public static function filters(): array
    {
        $fields = [
            FilterField::select('status', 'Status', IntegrationEventStatus::options()),
            FilterField::select('data_source_id', 'Source', self::sourceOptions()),
            FilterField::text('outcome', 'Outcome'),
            FilterField::text('external_id', 'Their id'),
            FilterField::date('received_at', 'Received'),
            FilterField::date('processed_at', 'Processed'),
            FilterField::boolean('signature_verified', 'Signature verified'),
            FilterField::boolean('is_sandbox', 'Sandbox'),
            FilterField::text('ip_address', 'From'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }

    /**
     * @return array<string, string>
     */
    public static function sourceOptions(): array
    {
        /** @var array<string, string> $options */
        $options = DataSource::query()->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id) => [(string) $id => $name])
            ->all();

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
                return 'integration_events.'.$column->sortColumn();
            }
        }

        return null;
    }

    /**
     * Searching the **payload** is the point of this screen.
     *
     * Somebody asking "did that come through" has a name or an email address,
     * not an event id, and the body is the only place either appears. It is a
     * LIKE over a longText column, which is slow — but a log nobody can search
     * is a log nobody uses, and the alternative is asking them to read pages.
     *
     * @return array<int, string>
     */
    public static function searchColumns(): array
    {
        return ['external_id', 'payload', 'error'];
    }
}
