<?php

namespace App\Livewire\Deals;

use App\Domain\Deals\Actions\DeleteDealAction;
use App\Domain\Deals\Actions\MoveDealStageAction;
use App\Domain\Deals\DealExportSource;
use App\Domain\Deals\DealFields;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
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
use RuntimeException;

/**
 * The deals list, built on the shared data-view kit.
 *
 * The component owns the query — including visibleTo() — so a record outside the
 * viewer's access level never reaches the page, whichever view they are in.
 *
 * What is different here is the board: stages are configurable as of 3.1, so the
 * kanban columns come from a *pipeline* rather than an enum, and the screen
 * therefore has a pipeline selector. A board mixing two pipelines' stages would
 * put a deal in a column that does not apply to it.
 */
#[Title('Deals')]
class DealsIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;

    // Aliased so cellFor() below can hand anything it does not style back to
    // the kit's default rendering.
    use WithDataView {
        cellFor as defaultCellFor;
    }

    /**
     * The pipeline the board is showing, as an id. Empty means the default one.
     */
    #[Url(as: 'pipeline', except: '')]
    public string $pipelineId = '';

    /**
     * The quick filter chips from the UI standard. Kept separate from the
     * filter builder so one does not clobber the other.
     */
    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    public const QUICK_FILTERS = ['mine', 'open', 'won', 'overdue', 'closing_this_month'];

    public function mount(): void
    {
        $this->authorize('viewAny', Deal::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'deals';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return DealFields::columns();
    }

    /**
     * @return Builder<Deal>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Deal::query()
            ->visibleTo(auth()->user())
            // pipeline.stages because every row's stage chip and weighted value
            // ask the stage for its colour and probability. Without it the list
            // is one query per row.
            ->with(['owner:id,name', 'account:id,name', 'contact:id,first_name,last_name', 'pipeline.stages']);

        // The board only makes sense within one pipeline, so in kanban mode the
        // selection is a hard scope rather than a filter chip.
        if ($this->viewMode === 'kanban') {
            $pipeline = $this->boardPipeline();

            if ($pipeline !== null) {
                $query->where('deals.pipeline_id', $pipeline->getKey());
            }
        } elseif ($this->pipelineId !== '') {
            $query->where('deals.pipeline_id', (int) $this->pipelineId);
        }

        return match ($this->quickFilter) {
            'mine' => $query->where('deals.owner_id', auth()->id()),
            'open' => $query->open(),
            'won' => $query->withOutcome(StageOutcome::Won),
            'overdue' => $query->open()->whereNotNull('deals.expected_close_date')
                ->where('deals.expected_close_date', '<', now()->startOfDay()),
            'closing_this_month' => $query->open()
                ->whereBetween('deals.expected_close_date', [now()->startOfMonth(), now()->endOfMonth()]),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(DealFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return DealFields::searchColumns();
    }

    public function dataViewKanbanField(): ?string
    {
        return 'stage';
    }

    /**
     * The board's columns are the selected pipeline's stages, in their
     * configured order.
     *
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function dataViewKanbanColumns(): array
    {
        $pipeline = $this->boardPipeline();

        if ($pipeline === null) {
            return [];
        }

        return $pipeline->stages
            ->map(fn (PipelineStage $stage) => [
                'value' => $stage->key,
                'label' => $stage->name,
                'color' => $stage->color,
            ])
            ->values()
            ->all();
    }

    /**
     * The money figure in each board column's header.
     */
    public function dataViewKanbanSumField(): ?string
    {
        return 'value';
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('deals.export') === true
            ? app(DealExportSource::class)
            : null;
    }

    // -- The pipeline the board is on ----------------------------------------

    public function boardPipeline(): ?Pipeline
    {
        if ($this->pipelineId !== '') {
            $chosen = Pipeline::query()->with('stages')->whereKey((int) $this->pipelineId)->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return Pipeline::query()->with('stages')->where('is_default', true)->first()
            ?? Pipeline::query()->with('stages')->ordered()->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pipelineOptions(): array
    {
        return Pipeline::query()->with('stages')->ordered()->get()
            ->map(fn (Pipeline $pipeline) => [
                'value' => (string) $pipeline->id,
                'label' => $pipeline->name,
                'description' => $pipeline->stages->count().' '.str('stage')->plural($pipeline->stages->count()),
            ])
            ->values()
            ->all();
    }

    public function updatedPipelineId(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    // -- Cells ---------------------------------------------------------------

    /**
     * How each cell reads. Chips and links live here rather than in the view so
     * the table, grid, list and kanban all show a field the same way.
     */
    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Deal $record */
        return match ($column->key) {
            'name' => new HtmlString(
                '<a href="'.e(route('deals.show', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->name).'</a>'
            ),
            'account' => $record->account === null
                ? $this->blank()
                : new HtmlString(
                    '<a href="'.e(route('accounts.show', $record->account)).'" wire:navigate '
                    .'class="text-muted-foreground hover:text-accent hover:underline">'
                    .e($record->account->name).'</a>'
                ),
            'contact' => $record->contact === null
                ? $this->blank()
                : new HtmlString(
                    '<a href="'.e(route('contacts.show', $record->contact)).'" wire:navigate '
                    .'class="text-muted-foreground hover:text-accent hover:underline">'
                    .e($record->contact->fullName()).'</a>'
                ),
            'stage' => $this->stageChip($record),
            'value' => $record->value === null
                ? $this->blank()
                : NumberFormat::format((float) $record->value, 0),
            'weighted_value' => $record->value === null
                ? $this->blank()
                : new HtmlString(
                    '<span title="'.e($this->weightingTitle($record)).'">'
                    .e(NumberFormat::format($record->weightedValue(), 0)).'</span>'
                ),
            'expected_close_date' => $this->closeDateCell($record),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            'pipeline' => $record->pipeline === null ? $this->blank() : $record->pipeline->name,
            'close_reason' => $record->closeReason() === null
                ? $this->blank()
                : new HtmlString(ChipPalette::chip(
                    $record->closeReason()->label(),
                    $record->closeReason()->color()
                )),
            'closed_at' => $record->closed_at?->format('j M Y') ?? $this->blank(),
            default => $this->defaultCellFor($record, $column),
        };
    }

    /**
     * The stage as a chip, named and coloured by the configured stage when
     * there is one and by the enum when there is not.
     */
    private function stageChip(Deal $record): HtmlString
    {
        $stage = $record->configuredStage();

        return new HtmlString(ChipPalette::chip(
            $stage === null ? $record->stage()->label() : $stage->name,
            $stage === null ? $record->stage()->color() : $stage->color
        ));
    }

    /**
     * An overdue deal says so in the cell rather than only in a filter — a date
     * in the past is easy to scan past.
     */
    private function closeDateCell(Deal $record): string|HtmlString
    {
        if ($record->expected_close_date === null) {
            return $this->blank();
        }

        $formatted = $record->expected_close_date->format('j M Y');

        if (! $record->isOverdue()) {
            return $formatted;
        }

        return new HtmlString(
            '<span class="text-destructive" title="Past its expected close date">'.e($formatted).'</span>'
        );
    }

    private function weightingTitle(Deal $record): string
    {
        $stage = $record->configuredStage();
        $probability = $stage === null ? $record->stage()->probability() : $stage->probability;

        return NumberFormat::format((float) $record->value, 0).' at '.$probability.'%';
    }

    // -- Board drag ----------------------------------------------------------

    /**
     * A card dropped into another column.
     *
     * Overridden so the move goes through MoveDealStageAction — the only writer
     * of stage — rather than the kit's generic column update. 3.3 builds the
     * optimistic UI on top of this.
     */
    public function moveCard(int $id, string $value): bool
    {
        $deal = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($deal === null) {
            return false;
        }

        $this->authorize('update', $deal);

        try {
            $moved = app(MoveDealStageAction::class)($deal, $value);
        } catch (RuntimeException $exception) {
            // False sends the card back where it came from, and the person is
            // told why.
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return false;
        }

        if (! $moved) {
            // Dropped back into the column it was already in.
            return false;
        }

        $fresh = $deal->refresh();
        $movedStage = $fresh->configuredStage();
        $stageName = $movedStage === null ? $value : $movedStage->name;

        // A deal that landed in a closing stage has no reason recorded yet, and
        // that is the one thing the win/loss report cannot be given later
        // without somebody remembering.
        if (! $fresh->isOpen() && $fresh->close_reason === null) {
            $this->dispatch(
                'notify',
                type: 'success',
                message: $fresh->name.' is now '.$stageName.'. Open it to record why.',
            );

            return true;
        }

        $this->dispatch('notify', type: 'success', message: $fresh->name.' is now '.$stageName.'.');

        return true;
    }

    // -- Quick filters -------------------------------------------------------

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, self::QUICK_FILTERS, true) && $this->quickFilter !== $chip
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

    // -- Totals --------------------------------------------------------------

    /**
     * The value and weighted value of everything the current filters match.
     *
     * Runs its own aggregate over the whole filtered set rather than summing
     * the page, so the figure describes the data and not what happens to be on
     * screen. The weighted total needs each row's stage probability, so it
     * joins the configured stages rather than guessing.
     *
     * @return array{count: int, value: float, weighted: float}
     */
    #[Computed]
    public function totals(): array
    {
        // `applyScopes()` before `getQuery()`, never `getQuery()` alone.
        // Eloquent applies its global scopes at execution time, so dropping
        // straight to the base builder silently discards them — and Deal
        // soft-deletes, which would count removed deals in a figure printed
        // under the rows they are not in.
        $query = $this->dataViewQuery()
            ->applyScopes()
            ->getQuery()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order']);

        // COALESCE on the probability so a deal whose pipeline has no matching
        // stage counts at zero rather than dropping out of the sum entirely.
        $row = $query
            ->leftJoin('pipeline_stages', function ($join) {
                $join->on('pipeline_stages.pipeline_id', '=', 'deals.pipeline_id')
                    ->on('pipeline_stages.key', '=', 'deals.stage');
            })
            ->selectRaw('COUNT(DISTINCT deals.id) as row_count')
            ->selectRaw('COALESCE(SUM(deals.value), 0) as total_value')
            ->selectRaw('COALESCE(SUM(deals.value * COALESCE(pipeline_stages.probability, 0) / 100), 0) as weighted_value')
            ->first();

        return [
            'count' => (int) ($row->row_count ?? 0),
            'value' => round((float) ($row->total_value ?? 0), 2),
            'weighted' => round((float) ($row->weighted_value ?? 0), 2),
        ];
    }

    // -- Actions -------------------------------------------------------------

    public function delete(int $dealId): void
    {
        $deal = $this->dataViewBaseQuery()->whereKey($dealId)->first();

        if ($deal === null) {
            return;
        }

        $this->authorize('delete', $deal);

        app(DeleteDealAction::class)($deal);

        $this->clearSelection();
        $this->dispatch('deal-deleted', name: $deal->name);
    }

    public function deleteSelected(): void
    {
        $deals = $this->dataViewBaseQuery()->whereKey($this->selected)->get();
        $removed = 0;

        foreach ($deals as $deal) {
            if (auth()->user()?->can('delete', $deal)) {
                app(DeleteDealAction::class)($deal);
                $removed++;
            }
        }

        $this->clearSelection();
        $this->dispatch('deal-deleted', name: $removed.' '.str('deal')->plural($removed));
    }

    /**
     * @return array<string, string>
     */
    public function closeReasonOptions(): array
    {
        return DealCloseReason::options();
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
        return view('livewire.deals.deals-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
