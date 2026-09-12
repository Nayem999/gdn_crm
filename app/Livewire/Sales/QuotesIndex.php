<?php

namespace App\Livewire\Sales;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\QuoteExportSource;
use App\Domain\Sales\QuoteFields;
use App\Domain\Settings\NumberFormat;
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
 * The quotes list.
 *
 * Shows **current versions only** by default. A superseded row is history, and
 * listing it beside its replacement means the same quote appears three times
 * with only one of them mattering.
 */
#[Title('Quotes')]
class QuotesIndex extends Component
{
    use AuthorizesRequests;
    use WithDataView {
        cellFor as defaultCellFor;
    }

    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    #[Url(as: 'history', except: false)]
    public bool $includeSuperseded = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Quote::class);

        $this->mountWithDataView();
    }

    public function dataViewModule(): string
    {
        return 'quotes';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return QuoteFields::columns();
    }

    /**
     * @return Builder<Quote>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Quote::query()
            ->visibleTo(auth()->user())
            ->with('owner:id,name')
            ->when(! $this->includeSuperseded, fn (Builder $inner) => $inner->currentVersions());

        return match ($this->quickFilter) {
            'mine' => $query->where('quotes.owner_id', auth()->id()),
            'open' => $query->open(),
            'accepted' => $query->where('quotes.status', QuoteStatus::Accepted->value),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(QuoteFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return QuoteFields::searchColumns();
    }

    public function dataViewKanbanField(): ?string
    {
        return 'status';
    }

    /**
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function dataViewKanbanColumns(): array
    {
        $board = [];

        // Superseded is left off the board: it is history, and a column of it
        // would grow forever while nobody ever drags anything into it.
        foreach (QuoteStatus::cases() as $status) {
            if ($status !== QuoteStatus::Superseded) {
                $board[] = ['value' => $status->value, 'label' => $status->label(), 'color' => $status->color()];
            }
        }

        return $board;
    }

    public function dataViewKanbanSumField(): ?string
    {
        return 'total';
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('quotes.export') === true
            ? app(QuoteExportSource::class)
            : null;
    }

    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Quote $record */
        return match ($column->key) {
            'number' => new HtmlString(
                '<a href="'.e(route('quotes.edit', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->reference()).'</a>'
            ),
            'status' => new HtmlString(ChipPalette::chip($record->status()->label(), $record->status()->color())),
            'total' => NumberFormat::format((float) $record->total, 2),
            'issue_date' => $record->issue_date->toFormattedDateString(),
            'valid_until' => $record->valid_until?->toFormattedDateString() ?? $this->blank(),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            default => $this->defaultCellFor($record, $column),
        };
    }

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, ['mine', 'open', 'accepted'], true) && $this->quickFilter !== $chip
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
        return view('livewire.sales.quotes-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
