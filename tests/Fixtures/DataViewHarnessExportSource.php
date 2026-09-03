<?php

namespace Tests\Fixtures;

use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\Filters\FilterGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the harness list from saved export state, the way a real module
 * will: its own query, its own scope, the browser naming nothing.
 */
class DataViewHarnessExportSource implements DataViewExportSource
{
    /**
     * @return Builder<DataViewRecord>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $query = DataViewRecord::query();

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        if ($request->search !== '') {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            $this->filterFields()
        );

        if (in_array($request->sortBy, ['name', 'stage', 'value', 'closes_on'], true)) {
            $query->orderBy($request->sortBy, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        return array_map(
            fn (string $key) => $record->getAttribute($key),
            $request->columnKeys()
        );
    }

    public function exportTitle(): string
    {
        return 'Data view records';
    }

    /**
     * @return array<string, FilterField>
     */
    private function filterFields(): array
    {
        return collect([
            FilterField::text('name', 'Name'),
            FilterField::select('stage', 'Stage', ['new' => 'New', 'won' => 'Won', 'lost' => 'Lost']),
            FilterField::number('value', 'Value'),
            FilterField::date('closes_on', 'Closes on'),
            FilterField::boolean('is_starred', 'Starred'),
        ])->keyBy('key')->all();
    }
}
