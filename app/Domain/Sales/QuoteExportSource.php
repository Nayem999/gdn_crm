<?php

namespace App\Domain\Sales;

use App\Domain\Sales\Models\Quote;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the quotes list for an export.
 *
 * Including visibleTo(), so an export can never contain quotes the person could
 * not see on screen — and quotes carry prices and, by inference, margins.
 */
class QuoteExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Quote>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Quote::query()->visibleTo($user)->with('owner');

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            QuoteFields::filters()
        );

        $sortable = QuoteFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('quotes.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Quote $record */
        return array_map(fn (string $key) => match ($key) {
            'number' => $record->reference(),
            'status' => $record->status()->label(),
            'owner' => $record->owner?->name,
            'issue_date' => $record->issue_date->format('Y-m-d'),
            'valid_until' => $record->valid_until?->format('Y-m-d'),
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Quotes';
    }
}
