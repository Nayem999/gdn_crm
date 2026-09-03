<?php

namespace App\Livewire\Accounts;

use App\Domain\Accounts\AccountExportSource;
use App\Domain\Accounts\AccountFields;
use App\Domain\Accounts\Actions\DeleteAccountAction;
use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Accounts\Models\Account;
use App\Domain\Settings\NumberFormat;
use App\Domain\Shared\Concerns\ExportsDataView;
use App\Domain\Shared\Concerns\WithDataView;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\UI\ChipPalette;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The accounts list, built on the shared data-view kit.
 *
 * The component owns the query — including visibleTo() — so a record outside the
 * viewer's access level never reaches the page, whichever view they are in.
 */
#[Title('Accounts')]
class AccountsIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;

    // Aliased so cellFor() below can hand anything it does not style back to
    // the kit's default rendering.
    use WithDataView {
        cellFor as defaultCellFor;
    }

    /**
     * The quick filter chips from the UI standard. Kept separate from the
     * filter builder so one does not clobber the other.
     */
    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Account::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'accounts';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return AccountFields::columns();
    }

    /**
     * @return Builder<Account>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Account::query()
            ->visibleTo(auth()->user())
            ->with(['owner:id,name', 'parent:id,name']);

        return match ($this->quickFilter) {
            'mine' => $query->where('accounts.owner_id', auth()->id()),
            'subsidiaries' => $query->whereNotNull('accounts.parent_id'),
            'this_week' => $query->where('accounts.created_at', '>=', now()->startOfWeek()),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(AccountFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return AccountFields::searchColumns();
    }

    /**
     * Grouped by size band, not industry.
     *
     * Nineteen industry columns turn the board into a horizontal scroll with
     * every record off-screen; five size bands read as a segmentation view and
     * dragging between them is a change someone would actually want to make.
     */
    public function dataViewKanbanField(): ?string
    {
        return 'size';
    }

    /**
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function dataViewKanbanColumns(): array
    {
        $board = [];

        foreach (AccountSize::cases() as $size) {
            $board[] = [
                'value' => $size->value,
                'label' => $size->shortLabel(),
                'color' => $size->color(),
            ];
        }

        return $board;
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('accounts.export') === true
            ? app(AccountExportSource::class)
            : null;
    }

    /**
     * How each cell reads. Chips and links live here rather than in the view so
     * the table, grid, list and kanban all show a field the same way.
     */
    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Account $record */
        return match ($column->key) {
            'name' => new HtmlString(
                '<a href="'.e(route('accounts.show', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->name).'</a>'
            ),
            'industry' => $record->industry() === null
                ? $this->blank()
                : new HtmlString(ChipPalette::chip(
                    $record->industry()->label(),
                    $record->industry()->color()
                )),
            'size' => $record->size()?->shortLabel() ?? $this->blank(),
            'annual_revenue' => $record->annual_revenue === null
                ? $this->blank()
                : NumberFormat::format((float) $record->annual_revenue, 0),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            'parent' => $record->parent === null
                ? $this->blank()
                : new HtmlString(
                    '<a href="'.e(route('accounts.show', $record->parent)).'" wire:navigate '
                    .'class="text-muted-foreground hover:text-accent hover:underline">'
                    .e($record->parent->name).'</a>'
                ),
            'website' => $record->websiteUrl() === null
                ? $this->blank()
                : new HtmlString(
                    '<a href="'.e($record->websiteUrl()).'" target="_blank" rel="noopener noreferrer" '
                    .'class="text-accent hover:underline">'.e($record->website).'</a>'
                ),
            default => $this->defaultCellFor($record, $column),
        };
    }

    // -- Quick filters -------------------------------------------------------

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, ['mine', 'subsidiaries', 'this_week'], true)
            && $this->quickFilter !== $chip
            ? $chip
            : '';

        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $this->quickFilter = '';
        $this->clearFilters();
        $this->search = '';
    }

    // -- Actions -------------------------------------------------------------

    public function delete(int $accountId): void
    {
        $account = $this->dataViewBaseQuery()->whereKey($accountId)->first();

        if ($account === null) {
            return;
        }

        $this->authorize('delete', $account);

        app(DeleteAccountAction::class)($account);

        $this->clearSelection();
        $this->dispatch('account-deleted', name: $account->name);
    }

    public function deleteSelected(): void
    {
        $this->authorize('create', Account::class);

        $accounts = $this->dataViewBaseQuery()->whereKey($this->selected)->get();

        foreach ($accounts as $account) {
            if (auth()->user()?->can('delete', $account)) {
                app(DeleteAccountAction::class)($account);
            }
        }

        $count = $accounts->count();
        $this->clearSelection();
        $this->dispatch('account-deleted', name: $count.' accounts');
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->dataViewQuery()->paginate($this->perPage);
    }

    public function render(): View
    {
        return view('livewire.accounts.accounts-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
