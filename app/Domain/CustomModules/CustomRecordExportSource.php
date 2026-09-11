<?php

namespace App\Domain\CustomModules;

use App\Domain\CustomModules\Models\CustomRecord;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds one generated module's list for an export.
 *
 * Which module comes from `ExportRequest::$module`, which the data view kit
 * already fills with `dataViewModule()` — the module key. It is resolved
 * through the registry rather than trusted, so a tampered request cannot widen
 * the export to every generated module at once.
 *
 * visibleTo() is re-applied for the same reason every other source does it: a
 * queued export runs with no session.
 */
class CustomRecordExportSource implements DataViewExportSource
{
    /**
     * @return Builder<CustomRecord>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);
        $module = app(CustomModuleRegistry::class)->find($request->module);

        if ($module === null) {
            // Not an empty query over every module: a request naming a module
            // that has gone gets nothing at all.
            abort(404);
        }

        $query = CustomRecord::query()
            ->visibleTo($user)
            ->forModule($module)
            ->with('owner');

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            CustomModuleFields::filters($module),
        );

        $sortable = CustomModuleFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('custom_records.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var CustomRecord $record */
        return array_map(fn (string $key) => match ($key) {
            'owner' => $record->owner?->name,
            'created_at' => $record->created_at?->format('Y-m-d'),
            // Custom field cells are filled by DataViewExport, which is where
            // every module's are — see .ai/rules/custom-fields.md.
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Records';
    }
}
