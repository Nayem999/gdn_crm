<?php

namespace App\Domain\Leads;

use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the leads list for an export.
 *
 * visibleTo() is re-applied: a queued export runs with no session, so without
 * it an export could hand someone rows they never had access to.
 */
class LeadExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Lead>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Lead::query()->visibleTo($user)->with(['assignees.user:id,name', 'leadOwner:id,name']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            LeadFields::filters()
        );

        $sortable = LeadFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('leads.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Lead $record */
        return array_map(fn (string $key) => match ($key) {
            'name' => $record->fullName(),
            'status' => $record->status()->label(),
            'source' => $record->source()?->label(),
            'assignees' => $record->assignees->map(
                fn (LeadAssignee $assignee) => $assignee->priority === null
                    ? $assignee->user->name
                    : $assignee->user->name.' ('.$assignee->priority.')'
            )->implode(', '),
            'lead_owner' => $record->leadOwner?->name,
            'days_in_status' => $record->daysInStatus(),
            // The number on its own says little outside the app.
            'score' => $record->score.' ('.$record->grade()->label().')',
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Leads';
    }
}
