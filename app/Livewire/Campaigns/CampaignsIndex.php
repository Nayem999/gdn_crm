<?php

namespace App\Livewire\Campaigns;

use App\Domain\Campaigns\Actions\DeleteCampaignAction;
use App\Domain\Campaigns\CampaignExportSource;
use App\Domain\Campaigns\CampaignFields;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Settings\DisplayTime;
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
 * Every campaign the company runs, on the shared data-view kit.
 *
 * The component owns the query — including visibleTo() — so a row outside the
 * viewer's access level never reaches the page, whichever view they are in.
 *
 * The lead and deal counts are loaded with the page rather than counted per
 * row: a list of fifty campaigns would otherwise be a hundred extra queries,
 * and these two numbers are the reason anybody opens this screen.
 */
#[Title('Campaigns')]
class CampaignsIndex extends Component
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
        $this->authorize('viewAny', Campaign::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'campaigns';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return CampaignFields::columns();
    }

    /**
     * @return Builder<Campaign>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Campaign::query()
            ->visibleTo(auth()->user())
            ->with('owner:id,name')
            ->withCount(['leads', 'deals']);

        return match ($this->quickFilter) {
            'running' => $query->where('status', CampaignStatus::Active->value),
            'planned' => $query->where('status', CampaignStatus::Planned->value),
            // Over budget is a question about two columns rather than one, so
            // it cannot be a filter-builder row — which is exactly what a quick
            // chip is for.
            'overspent' => $query->whereNotNull('budget')
                ->where('budget', '>', 0)
                ->whereColumn('actual_cost', '>', 'budget'),
            'mine' => $query->where('owner_id', auth()->id()),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(CampaignFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return CampaignFields::searchColumns();
    }

    /**
     * Grouped by status, which is the one grouping worth dragging between:
     * moving a campaign to Completed is a change somebody means.
     */
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

        foreach (CampaignStatus::cases() as $status) {
            $board[] = [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ];
        }

        return $board;
    }

    /**
     * The board's headers carry what each column has cost.
     */
    public function dataViewKanbanSumField(): ?string
    {
        return 'actual_cost';
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('campaigns.export') === true
            ? app(CampaignExportSource::class)
            : null;
    }

    /**
     * How each cell reads. Chips and links live here rather than in the view so
     * the table, grid, list and kanban all show a field the same way.
     */
    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Campaign $record */
        return match ($column->key) {
            'name' => new HtmlString(
                '<a href="'.e(route('campaigns.show', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->name).'</a>'
            ),
            'status' => new HtmlString(ChipPalette::chip($record->status()->label(), $record->status()->color())),
            'type' => new HtmlString(ChipPalette::chip($record->type()->label(), $record->type()->color())),
            'start_date' => $record->start_date === null ? $this->blank() : DisplayTime::date($record->start_date),
            'end_date' => $record->end_date === null ? $this->blank() : DisplayTime::date($record->end_date),
            'budget' => $record->budget() === null ? $this->blank() : NumberFormat::format($record->budget(), 2),
            'actual_cost' => NumberFormat::format($record->cost(), 2),
            'expected_revenue' => $record->expected_revenue === null
                ? $this->blank()
                : NumberFormat::format((float) $record->expected_revenue, 2),
            'budget_used' => $this->budgetUsed($record),
            'leads_count' => (string) ($record->leads_count ?? 0),
            'deals_count' => (string) ($record->deals_count ?? 0),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            default => $this->defaultCellFor($record, $column),
        };
    }

    /**
     * Budget consumption, coloured only when it has gone past the budget.
     *
     * Over 100 is shown as it is rather than capped: a campaign that has
     * overspent should read as having overspent, and a bar pinned at full would
     * hide exactly the campaigns somebody needs to look at.
     */
    private function budgetUsed(Campaign $campaign): HtmlString
    {
        $used = $campaign->budgetUsedPercent();

        if ($used === null) {
            return $this->blank();
        }

        $class = $used > 100 ? 'font-medium text-destructive' : 'text-foreground';

        return new HtmlString('<span class="'.$class.'">'.NumberFormat::format($used, 1).'%</span>');
    }

    // -- Quick filters -------------------------------------------------------

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, ['running', 'planned', 'overspent', 'mine'], true)
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

    public function delete(int $campaignId): void
    {
        $campaign = $this->dataViewBaseQuery()->whereKey($campaignId)->first();

        if ($campaign === null) {
            return;
        }

        $this->authorize('delete', $campaign);

        app(DeleteCampaignAction::class)($campaign);

        $this->dispatch('notify', type: 'success', message: $campaign->name.' was removed.');
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
        return view('livewire.campaigns.campaigns-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
