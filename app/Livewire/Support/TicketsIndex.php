<?php

namespace App\Livewire\Support;

use App\Domain\Settings\DisplayTime;
use App\Domain\Shared\Concerns\ExportsDataView;
use App\Domain\Shared\Concerns\WithDataView;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\UI\ChipPalette;
use App\Domain\Support\Actions\ChangeTicketStatusAction;
use App\Domain\Support\Actions\DeleteTicketAction;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketExportSource;
use App\Domain\Support\TicketFields;
use Carbon\CarbonInterface;
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
 * The support queue, built on the shared data-view kit.
 *
 * The component owns the query — including visibleTo() — so a ticket outside
 * the viewer's access level never reaches the page, whichever view they are in.
 *
 * The board groups by status, and a drag is a real status change: it goes
 * through ChangeTicketStatusAction, which is the only writer of the column and
 * the owner of the two stamps that follow it.
 */
#[Title('Support')]
class TicketsIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;

    // Aliased so cellFor() can hand anything it does not style back to the kit.
    use WithDataView {
        cellFor as defaultCellFor;
    }

    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    public const QUICK_FILTERS = ['mine', 'open', 'unlinked', 'urgent', 'resolved'];

    public function mount(): void
    {
        $this->authorize('viewAny', Ticket::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'tickets';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return TicketFields::columns();
    }

    /**
     * @return Builder<Ticket>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Ticket::query()
            ->visibleTo(auth()->user())
            ->with(['owner:id,name', 'contact:id,first_name,last_name', 'account:id,name']);

        // Most urgent first, then oldest, when nobody has chosen a sort. That
        // is the order an agent works a queue in, and it is applied to the base
        // query rather than by defaulting $sortBy because the kit's sort
        // property is bound to the URL.
        if ($this->sortBy === '') {
            $query->orderByDesc('tickets.priority')->orderBy('tickets.created_at');
        }

        return match ($this->quickFilter) {
            'mine' => $query->where('tickets.owner_id', auth()->id()),
            'open' => $query->open(),
            // Not "unassigned": every ticket has an agent, because the column
            // is NOT NULL. What actually goes missing is a ticket nobody has
            // attached to a customer, which cannot be found by searching for
            // the person who raised it.
            'unlinked' => $query->open()->whereNull('tickets.contact_id')->whereNull('tickets.account_id'),
            'urgent' => $query->open()->where('tickets.priority', '>=', TicketPriority::High->value),
            'resolved' => $query->whereNotNull('tickets.resolved_at'),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(TicketFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return TicketFields::searchColumns();
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
        return array_map(
            fn (TicketStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            TicketStatus::cases()
        );
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('tickets.export') === true
            ? app(TicketExportSource::class)
            : null;
    }

    // -- Cells ---------------------------------------------------------------

    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Ticket $record */
        return match ($column->key) {
            'subject' => new HtmlString(
                '<a href="'.e(route('tickets.show', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->subject).'</a>'
            ),
            'number' => new HtmlString('<span class="font-mono text-xs text-muted-foreground">'.e($record->reference()).'</span>'),
            'status' => new HtmlString(ChipPalette::chip($record->status()->label(), $record->status()->color())),
            'priority' => new HtmlString(ChipPalette::chip($record->priority()->label(), $record->priority()->color())),
            'source' => $record->source()->label(),
            'contact' => $this->contactCell($record),
            'account' => $this->accountCell($record),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            'age' => $this->ageCell($record),
            // DisplayTime takes a moment, not a maybe: the office clock is
            // the only place a stored UTC value is converted, and a null has
            // nothing to convert.
            'created_at' => $this->moment($record->created_at),
            'resolved_at' => $this->moment($record->resolved_at),
            'closed_at' => $this->moment($record->closed_at),
            default => $this->defaultCellFor($record, $column),
        };
    }

    /**
     * A stored moment on the office clock, or a dash.
     */
    private function moment(?CarbonInterface $moment): string|HtmlString
    {
        return $moment === null ? $this->blank() : DisplayTime::display($moment)->format('j M Y, H:i');
    }

    private function contactCell(Ticket $record): HtmlString
    {
        if ($record->contact === null) {
            return $this->blank();
        }

        return new HtmlString(
            '<a href="'.e(route('contacts.show', $record->contact)).'" wire:navigate '
            .'class="text-muted-foreground hover:text-accent hover:underline">'
            .e($record->contact->fullName()).'</a>'
        );
    }

    private function accountCell(Ticket $record): HtmlString
    {
        if ($record->account === null) {
            return $this->blank();
        }

        return new HtmlString(
            '<a href="'.e(route('accounts.show', $record->account)).'" wire:navigate '
            .'class="text-muted-foreground hover:text-accent hover:underline">'
            .e($record->account->name).'</a>'
        );
    }

    /**
     * How long it has been sitting.
     *
     * An open ticket that has been waiting more than a day says so in colour,
     * because a queue sorted by priority hides an old low-priority ticket at
     * the bottom and that is exactly the one people forget.
     */
    private function ageCell(Ticket $record): HtmlString
    {
        $hours = $record->ageInHours();
        $label = $hours < 24
            ? round($hours, 1).' h'
            : round($hours / 24).' d';

        if (! $record->isOpen() || $hours < 24) {
            return new HtmlString(e($label));
        }

        return new HtmlString(
            '<span class="text-amber-600 dark:text-amber-400" title="Open for more than a day">'.e($label).'</span>'
        );
    }

    // -- Board drag ----------------------------------------------------------

    /**
     * A card dropped into another status column.
     *
     * Overridden so the move goes through the action that owns `status` rather
     * than the kit's generic column update, and so every refusal comes back as
     * false — which is what puts the card back where it came from.
     */
    public function moveCard(int $id, string $value): bool
    {
        $ticket = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($ticket === null) {
            return false;
        }

        $this->authorize('update', $ticket);

        $status = TicketStatus::tryFrom($value);

        if ($status === null) {
            return false;
        }

        if (! app(ChangeTicketStatusAction::class)($ticket, $status)) {
            // Dropped back into the column it was already in.
            return false;
        }

        $this->dispatch(
            'notify',
            type: 'success',
            message: $ticket->reference().' is now '.strtolower($status->label()).'.',
        );

        return true;
    }

    // -- Actions -------------------------------------------------------------

    public function delete(int $id): void
    {
        $ticket = $this->dataViewBaseQuery()->whereKey($id)->first();

        if ($ticket === null) {
            return;
        }

        $this->authorize('delete', $ticket);

        app(DeleteTicketAction::class)($ticket);

        $this->clearSelection();
        $this->dispatch('ticket-deleted', name: $ticket->reference());
    }

    public function deleteSelected(): void
    {
        $tickets = $this->dataViewBaseQuery()->whereKey($this->selected)->get();
        $removed = 0;

        foreach ($tickets as $ticket) {
            if (auth()->user()?->can('delete', $ticket)) {
                app(DeleteTicketAction::class)($ticket);
                $removed++;
            }
        }

        $this->clearSelection();
        $this->dispatch('ticket-deleted', name: $removed.' '.str('ticket')->plural($removed));
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
     * What the current filters contain, counted over the whole filtered set
     * rather than the page — so the figures describe the data and not what
     * happens to be on screen.
     *
     * @return array{count: int, open: int, urgent: int, resolved: int}
     */
    #[Computed]
    public function totals(): array
    {
        // applyScopes() before getQuery(), or soft-deleted tickets come back
        // into the counts — see .ai/rules/models.md.
        $query = $this->dataViewQuery()
            ->applyScopes()
            ->getQuery()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order']);

        $open = TicketStatus::openValues();

        $row = $query
            ->selectRaw('COUNT(*) as row_count')
            // Placeholders counted from the enum, not written out: adding a
            // status would otherwise leave this silently one short.
            ->selectRaw(
                'COALESCE(SUM(tickets.status IN ('.implode(', ', array_fill(0, count($open), '?')).')), 0) as open_count',
                $open
            )
            ->selectRaw('COALESCE(SUM(tickets.priority >= ?), 0) as urgent_count', [TicketPriority::High->value])
            ->selectRaw('COALESCE(SUM(tickets.resolved_at IS NOT NULL), 0) as resolved_count')
            ->first();

        return [
            'count' => (int) ($row->row_count ?? 0),
            'open' => (int) ($row->open_count ?? 0),
            'urgent' => (int) ($row->urgent_count ?? 0),
            'resolved' => (int) ($row->resolved_count ?? 0),
        ];
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
        return view('livewire.support.tickets-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
