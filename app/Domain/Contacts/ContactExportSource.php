<?php

namespace App\Domain\Contacts;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the contacts list for an export.
 *
 * visibleTo() is re-applied here: a queued export runs with no session, so
 * without it an export could hand someone rows they never had access to.
 */
class ContactExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Contact>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Contact::query()->visibleTo($user)->with(['account', 'owner']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            ContactFields::filters()
        );

        $sortable = ContactFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('contacts.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Contact $record */
        return array_map(fn (string $key) => match ($key) {
            'name' => $record->fullName(),
            'account' => $record->account?->name,
            'department' => $record->department()?->label(),
            'is_primary' => $record->is_primary ? 'Yes' : 'No',
            'owner' => $record->owner?->name,
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Contacts';
    }
}
