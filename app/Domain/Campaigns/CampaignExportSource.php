<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the campaigns list for an export.
 *
 * A queued export cannot carry a query, so it carries state and this rebuilds
 * it — including visibleTo(), so an export can never contain rows the person
 * could not see on screen.
 */
class CampaignExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Campaign>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Campaign::query()
            ->visibleTo($user)
            ->with('owner')
            // The counts are columns on the list, so an export without them
            // would be a different report from the one somebody was looking at.
            ->withCount(['leads', 'deals']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            CampaignFields::filters()
        );

        $sortable = CampaignFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('campaigns.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Campaign $record */
        return array_map(fn (string $key) => match ($key) {
            'type' => $record->type()->label(),
            'status' => $record->status()->label(),
            'budget_used' => $record->budgetUsedPercent(),
            'leads_count' => $record->leads_count ?? 0,
            'deals_count' => $record->deals_count ?? 0,
            'owner' => $record->owner?->name,
            'start_date' => $record->start_date?->format('Y-m-d'),
            'end_date' => $record->end_date?->format('Y-m-d'),
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Campaigns';
    }
}
