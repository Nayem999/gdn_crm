<?php

namespace App\Domain\Deals;

use App\Domain\Deals\Models\Deal;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the deals list for an export.
 *
 * A queued export cannot carry a query, so it carries state and this rebuilds
 * it — crucially including visibleTo(), because a queued export runs without a
 * session and forgetting it would email someone rows they could not see.
 */
class DealExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Deal>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Deal::query()
            ->visibleTo($user)
            // pipeline.stages is eager loaded because the weighted value of
            // every row asks its stage for a probability; without it the export
            // is one query per row.
            ->with(['owner', 'account', 'contact', 'pipeline.stages']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            DealFields::filters()
        );

        $sortable = DealFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('deals.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Deal $record */
        return array_map(fn (string $key) => match ($key) {
            'account' => $record->account?->name,
            'contact' => $record->contact?->fullName(),
            'owner' => $record->owner?->name,
            'pipeline' => $record->pipeline?->name,
            'stage' => $this->stageName($record),
            // Exported as a number, not a formatted string: a spreadsheet
            // column of "£1,200.00" cannot be summed.
            'weighted_value' => $record->weightedValue(),
            'close_reason' => $record->closeReason()?->label(),
            'closed_at' => $record->closed_at?->format('Y-m-d'),
            'expected_close_date' => $record->expected_close_date?->format('Y-m-d'),
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Deals';
    }

    /**
     * The configured stage's name when its pipeline has one, and the enum's
     * label when it does not — a 2.6 deal on no pipeline still exports a stage.
     */
    private function stageName(Deal $record): string
    {
        $stage = $record->configuredStage();

        return $stage === null ? $record->stage()->label() : $stage->name;
    }
}
