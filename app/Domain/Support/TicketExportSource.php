<?php

namespace App\Domain\Support;

use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the ticket queue for an export.
 *
 * A queued export cannot carry a query, so it carries state and this rebuilds
 * it — including visibleTo(), because a queued export runs without a session
 * and forgetting it would send somebody rows they could not see.
 */
class TicketExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Ticket>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Ticket::query()
            ->visibleTo($user)
            ->with(['owner:id,name', 'contact:id,first_name,last_name', 'account:id,name']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            TicketFields::filters()
        );

        $sortable = TicketFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('tickets.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Ticket $record */
        return array_map(fn (string $key) => match ($key) {
            'status' => $record->status()->label(),
            // The label, not the stored rank: a spreadsheet column of 3s means
            // nothing to whoever opens it.
            'priority' => $record->priority()->label(),
            'source' => $record->source()->label(),
            'contact' => $record->contact?->fullName(),
            'account' => $record->account?->name,
            'owner' => $record->owner?->name,
            // A number, not a formatted string: a column of "3.5 hours" cannot
            // be averaged.
            'age' => $record->ageInHours(),
            'created_at' => $record->created_at?->format('Y-m-d H:i'),
            'resolved_at' => $record->resolved_at?->format('Y-m-d H:i'),
            'closed_at' => $record->closed_at?->format('Y-m-d H:i'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Tickets';
    }
}
