<?php

namespace App\Livewire\Leads;

use App\Domain\Deals\PipelineModules;
use App\Domain\Leads\Actions\ChangeLeadStatusAction;
use App\Domain\Leads\Actions\DeleteLeadAction;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadExportSource;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
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
 * The lead database, on the shared data-view kit.
 *
 * The first module whose kanban is a real pipeline: columns are lead statuses,
 * dragging a card is a status transition, and the board enforces the same
 * transition rules the form does.
 */
#[Title('Leads')]
class LeadsIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;

    // Aliased so cellFor() can hand anything it does not style back to the kit.
    use WithDataView {
        cellFor as defaultCellFor;
    }

    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'leads';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return LeadFields::columns();
    }

    /**
     * @return Builder<Lead>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Lead::query()
            ->visibleTo(auth()->user())
            ->with('owner:id,name');

        return match ($this->quickFilter) {
            'mine' => $query->where('leads.owner_id', auth()->id()),
            'open' => $query->open(),
            'stalled' => $query->open()->where('leads.status_changed_at', '<=', now()->subDays(14)),
            'this_week' => $query->where('leads.created_at', '>=', now()->startOfWeek()),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(LeadFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return LeadFields::searchColumns();
    }

    /**
     * The status chip, named and coloured as this module is configured.
     *
     * Falls back to the lead's own enum for a key the configured set does not
     * hold — a record can outlive the stage it was put in, and a blank chip
     * would hide it rather than explain it.
     */
    private function statusChip(Lead $record): HtmlString
    {
        $key = (string) $record->getAttributeValue('status');
        $status = PipelineModules::status('leads', $key);

        return new HtmlString(ChipPalette::chip(
            $status['label'] ?? $record->status()->label(),
            $status['color'] ?? $record->status()->color(),
        ));
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
        // The configured pipeline's stages when this module has one, and the
        // LeadStatus enum when it does not — PipelineModules answers that once
        // so the board, the filter builder and the status chip cannot disagree
        // about what a lead's statuses are.
        $board = [];

        foreach (PipelineModules::statuses('leads') as $status) {
            $board[] = [
                'value' => $status['value'],
                'label' => $status['label'],
                'color' => $status['color'],
            ];
        }

        return $board;
    }

    /**
     * The board totals what the pipeline might be worth, which is the whole
     * point of looking at leads as columns.
     */
    public function dataViewKanbanSumField(): ?string
    {
        return 'estimated_value';
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('leads.export') === true
            ? app(LeadExportSource::class)
            : null;
    }

    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Lead $record */
        return match ($column->key) {
            'name' => new HtmlString(
                '<a href="'.e(route('leads.show', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->fullName()).'</a>'
            ),
            // Through the registry, not the enum: a configured pipeline renames
            // and recolours these, and a chip that read the enum would disagree
            // with the board and the filter beside it.
            'status' => $this->statusChip($record),
            'source' => $record->source() === null
                ? $this->blank()
                : new HtmlString(ChipPalette::chip(
                    $record->source()->label(),
                    $record->source()->color()
                )),
            'estimated_value' => $record->estimated_value === null
                ? $this->blank()
                : NumberFormat::format((float) $record->estimated_value, 0),
            'email' => $record->email === null
                ? $this->blank()
                : new HtmlString(
                    '<a href="mailto:'.e($record->email).'" class="text-accent hover:underline">'
                    .e($record->email).'</a>'
                ),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            'days_in_status' => (string) $record->daysInStatus(),
            'score' => new HtmlString(ChipPalette::chip(
                $record->score.' · '.$record->grade()->label(),
                $record->grade()->color()
            )),
            default => $this->defaultCellFor($record, $column),
        };
    }

    // -- Quick filters -------------------------------------------------------

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, ['mine', 'open', 'stalled', 'this_week'], true)
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

    // -- Status ---------------------------------------------------------------

    /**
     * A board drag.
     *
     * Overrides the kit's generic move so the transition rules apply: the kit
     * only checks that the target column exists, which for a pipeline is not
     * enough — nothing may be dragged into Converted, and a converted lead
     * cannot be dragged back out.
     */
    public function moveCard(int $id, string $value): bool
    {
        $target = LeadStatus::tryFrom($value);
        $lead = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($target === null || $lead === null) {
            return false;
        }

        $this->authorize('update', $lead);

        try {
            app(ChangeLeadStatusAction::class)($lead, $target);
        } catch (RuntimeException $exception) {
            // False sends the card back where it came from, and the person is
            // told why.
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return false;
        }

        $this->dispatch('lead-updated', message: $lead->fullName().' is now '.$target->label().'.');

        return true;
    }

    // -- Actions -------------------------------------------------------------

    public function delete(int $leadId): void
    {
        $lead = $this->dataViewBaseQuery()->whereKey($leadId)->first();

        if ($lead === null) {
            return;
        }

        $this->authorize('delete', $lead);

        app(DeleteLeadAction::class)($lead);

        $this->clearSelection();
        $this->dispatch('lead-deleted', name: $lead->fullName());
    }

    public function deleteSelected(): void
    {
        $this->authorize('create', Lead::class);

        $leads = $this->dataViewBaseQuery()->whereKey($this->selected)->get();
        $removed = 0;

        foreach ($leads as $lead) {
            if (auth()->user()?->can('delete', $lead)) {
                app(DeleteLeadAction::class)($lead);
                $removed++;
            }
        }

        $this->clearSelection();
        $this->dispatch('lead-deleted', name: $removed.' leads');
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
        return view('livewire.leads.leads-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
