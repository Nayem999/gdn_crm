<?php

namespace App\Domain\Accounts;

use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuilds the accounts list for an export.
 *
 * A queued export cannot carry a query, so it carries state and this rebuilds
 * it — crucially including visibleTo(), so an export can never contain rows the
 * person could not see on screen.
 */
class AccountExportSource implements DataViewExportSource
{
    /**
     * @return Builder<Account>
     */
    public function exportQuery(ExportRequest $request): Builder
    {
        $user = User::query()->findOrFail($request->userId);

        $query = Account::query()->visibleTo($user)->with(['owner', 'parent']);

        if ($request->onlySelected) {
            $query->whereKey($request->selectedIds);
        }

        $query->search($request->search);

        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray($request->filters),
            AccountFields::filters()
        );

        $sortable = AccountFields::sortColumn($request->sortBy);

        if ($sortable !== null) {
            $query->orderBy($sortable, $request->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('accounts.id');
    }

    /**
     * @return array<int, string|int|float|null>
     */
    public function exportRow(Model $record, ExportRequest $request): array
    {
        /** @var Account $record */
        return array_map(fn (string $key) => match ($key) {
            'industry' => $record->industry()?->label(),
            'size' => $record->size()?->label(),
            'owner' => $record->owner?->name,
            'parent' => $record->parent?->name,
            'created_at' => $record->created_at?->format('Y-m-d'),
            default => $record->getAttribute($key),
        }, $request->columnKeys());
    }

    public function exportTitle(): string
    {
        return 'Accounts';
    }

    /**
     * @return array<string, string>
     */
    public static function industryOptions(): array
    {
        return Industry::options();
    }

    /**
     * @return array<string, string>
     */
    public static function sizeOptions(): array
    {
        return AccountSize::options();
    }
}
