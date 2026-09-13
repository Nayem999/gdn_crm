<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the delivery log for an export.
 *
 * There is no access scope to re-apply here — a delivery belongs to the
 * installation, not to a person — so what gates it is the permission, checked
 * before the export is offered at all.
 *
 * The **payload is not an exportable column**. A spreadsheet of raw bodies is a
 * spreadsheet of customer data leaving the application by the easiest possible
 * route, and somebody who needs one body can read it on the screen.
 */
class IntegrationEventExportSource implements DataViewExportSource
{
    /**
     * @return Builder<IntegrationEvent>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $query = IntegrationEvent::query()->with('dataSource:id,name');

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            IntegrationEventFields::filters()
        );

        $sortable = IntegrationEventFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        } else {
            $query->latestFirst();
        }

        return $query->orderBy('integration_events.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var IntegrationEvent $record */
        return array_map(fn (string $key) => match ($key) {
            'source' => $record->dataSource?->name,
            'status' => $record->status()->label(),
            'record' => $record->record_id === null ? null : class_basename((string) $record->record_type).' #'.$record->record_id,
            'payload_size' => $record->payloadBytes(),
            'received_at' => $record->received_at->format('Y-m-d H:i:s'),
            'processed_at' => $record->processed_at?->format('Y-m-d H:i:s'),
            // Truncated: a database error can echo an entire query, and a
            // spreadsheet cell is not where anybody reads one.
            'error' => $record->error === null ? null : mb_substr($record->error, 0, 300),
            // Never the body. See the note on this class.
            'payload' => null,
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Delivery log';
    }
}
