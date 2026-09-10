<?php

namespace App\Domain\Activities;

use App\Domain\Activities\Models\Activity;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the activities list for an export.
 *
 * A queued export cannot carry a query, so it carries state and this rebuilds
 * it — including visibleTo(), because a queued export runs without a session
 * and forgetting it would send someone rows they could not see.
 */
class ActivityExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Activity>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Activity::query()
            ->visibleTo($user)
            ->with(['owner', 'related']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            ActivityFields::filters()
        );

        $sortable = ActivityFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        } else {
            // The same fallback the screen uses when nothing is sorted, so an
            // export is in the order the person was looking at.
            $query->orderBy('activities.due_at');
        }

        return $query->orderBy('activities.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Activity $record */
        return array_map(fn (string $key) => match ($key) {
            'type' => $record->type()->label(),
            'status' => $record->status()->label(),
            // The label, not the rank: a spreadsheet column of 3s means nothing
            // to whoever opens it.
            'priority' => $record->priority()->label(),
            'related' => $this->relatedLabel($record),
            'owner' => $record->owner?->name,
            'recurrence' => $record->recurrence()?->label(),
            'due_at' => $record->all_day
                ? $record->due_at->format('Y-m-d')
                : $record->due_at->format('Y-m-d H:i'),
            'completed_at' => $record->completed_at?->format('Y-m-d H:i'),
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Activities';
    }

    private function relatedLabel(Activity $activity): ?string
    {
        $related = $activity->related;

        if ($related === null) {
            return null;
        }

        return ActivityRelations::typeLabel($related).': '.ActivityRelations::label($related);
    }
}
