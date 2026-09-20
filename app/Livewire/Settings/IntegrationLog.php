<?php

namespace App\Livewire\Settings;

use App\Domain\Ingestion\Actions\ReplayIntegrationEventAction;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\IntegrationEventExportSource;
use App\Domain\Ingestion\IntegrationEventFields;
use App\Domain\Ingestion\IntegrationHealth;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Shared\Concerns\ExportsDataView;
use App\Domain\Shared\Concerns\WithDataView;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\UI\ChipPalette;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every delivery, and what became of it.
 *
 * The screen somebody opens with "did that come through", which is why the
 * search covers the **payload**: they have a name or an email address, not an
 * event id, and the body is the only place either appears.
 *
 * The board groups by status rather than by source. "What is failing" is the
 * question; "which source" is a filter.
 */
#[Title('Delivery log')]
class IntegrationLog extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;
    use WithDataView {
        cellFor as defaultCellFor;
    }

    /**
     * Narrowed to one source, which is how the health dashboard links in.
     */
    #[Url(as: 'source', except: '')]
    public string $sourceId = '';

    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    public const QUICK_FILTERS = ['failed', 'today', 'sandbox', 'unmapped'];

    /**
     * The delivery being inspected, if any.
     */
    #[Url(as: 'event', except: null)]
    public ?int $inspectingId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', DataSource::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'integration-events';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return IntegrationEventFields::columns();
    }

    /**
     * @return Builder<IntegrationEvent>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = IntegrationEvent::query()->with('dataSource:id,name,target_module');

        // No visibleTo(): a delivery is not somebody's record. What gates this
        // screen is the permission, and the permission is the whole of it.
        if ($this->sourceId !== '') {
            $query->where('integration_events.data_source_id', (int) $this->sourceId);
        }

        if ($this->sortBy === '') {
            $query->latestFirst();
        }

        return match ($this->quickFilter) {
            'failed' => $query->failed(),
            'today' => $query->where('integration_events.received_at', '>=', now()->startOfDay()),
            'sandbox' => $query->where('integration_events.is_sandbox', true),
            // Arrived, was recorded, and made nothing — usually a mapping that
            // is not finished.
            'unmapped' => $query->whereNull('integration_events.record_id')
                ->where('integration_events.status', '!=', IntegrationEventStatus::Failed->value),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(IntegrationEventFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return IntegrationEventFields::searchColumns();
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
            fn (IntegrationEventStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            IntegrationEventStatus::cases()
        );
    }

    /**
     * A delivery's status is the pipeline's to set, never a drag's.
     *
     * Returning false for everything keeps the board read-only: a card dropped
     * into "Processed" has not been processed, and saying it has would make the
     * log lie about what happened.
     */
    public function moveCard(int $id, string $value): bool
    {
        return false;
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('integrations.view') === true
            ? app(IntegrationEventExportSource::class)
            : null;
    }

    // -- Cells ---------------------------------------------------------------

    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var IntegrationEvent $record */
        return match ($column->key) {
            'received_at' => $this->receivedCell($record),
            'source' => $record->dataSource === null ? $this->blank() : $record->dataSource->name,
            // Monospaced, because it is a string from somebody else's system
            // that has to be compared character for character against their
            // documentation — not a phrase to read.
            'event' => $record->event === null || $record->event === ''
                ? $this->blank()
                : new HtmlString('<span class="font-mono text-xs">'.e($record->event).'</span>'),
            'status' => new HtmlString(ChipPalette::chip($record->status()->label(), $record->status()->color())),
            'outcome' => $record->outcome === null
                ? $this->blank()
                : new HtmlString(ChipPalette::chip($record->outcome, $this->outcomeColour($record->outcome))),
            'record' => $this->recordCell($record),
            'error' => $record->error === null
                ? $this->blank()
                : new HtmlString('<span class="text-destructive">'.e(mb_substr($record->error, 0, 160)).'</span>'),
            'payload_size' => number_format($record->payloadBytes()).' B',
            'processed_at' => $record->processed_at?->format('j M Y, H:i') ?? $this->blank(),
            default => $this->defaultCellFor($record, $column),
        };
    }

    private function receivedCell(IntegrationEvent $record): HtmlString
    {
        return new HtmlString(
            '<button type="button" wire:click="inspect('.$record->id.')" '
            .'class="font-medium text-foreground hover:text-accent hover:underline">'
            .e($record->received_at->format('j M Y, H:i:s')).'</button>'
        );
    }

    private function recordCell(IntegrationEvent $record): HtmlString
    {
        if ($record->record_id === null) {
            return $this->blank();
        }

        $related = $record->record;

        if ($related === null) {
            // The row it made has since been removed. Saying so is more useful
            // than a blank, which reads as "it made nothing".
            return new HtmlString('<span class="text-muted-foreground" title="The record has since been removed">gone</span>');
        }

        $label = method_exists($related, 'displayName')
            ? $related->displayName()
            : class_basename($related).' #'.$related->getKey();

        return new HtmlString('<span class="text-muted-foreground">'.e($label).'</span>');
    }

    private function outcomeColour(string $outcome): string
    {
        return match ($outcome) {
            'created' => 'emerald',
            'updated' => 'blue',
            'failed' => 'rose',
            'sandbox' => 'violet',
            default => 'slate',
        };
    }

    // -- Inspecting ----------------------------------------------------------

    public function inspect(int $id): void
    {
        $this->inspectingId = $id;
    }

    public function stopInspecting(): void
    {
        $this->inspectingId = null;
    }

    public function inspecting(): ?IntegrationEvent
    {
        return $this->inspectingId === null
            ? null
            : IntegrationEvent::query()->with('dataSource')->find($this->inspectingId);
    }

    /**
     * The payload, laid out so somebody can read it.
     *
     * Re-encoded **only for display** — the stored bytes are what the signature
     * covered and what a replay sends, and they are never rewritten.
     */
    public function prettyPayload(): ?string
    {
        $event = $this->inspecting();

        if ($event === null || $event->payload === null) {
            return null;
        }

        $decoded = json_decode($event->payload, true);

        return $decoded === null
            ? $event->payload
            : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // -- Replay --------------------------------------------------------------

    public function canReplay(): bool
    {
        return auth()->user()?->can('integrations.manage') === true;
    }

    public function replay(int $id): void
    {
        $event = IntegrationEvent::query()->findOrFail($id);

        $this->authorize('update', $event->dataSource ?? new DataSource);

        app(ReplayIntegrationEventAction::class)($event);

        $this->dispatch('notify', type: 'success', message: 'Sent through again.');
    }

    public function replaySelected(): void
    {
        $events = $this->dataViewBaseQuery()->whereKey($this->selected)->get();
        $done = 0;

        foreach ($events as $event) {
            if ($event->dataSource !== null && auth()->user()?->can('update', $event->dataSource)) {
                app(ReplayIntegrationEventAction::class)($event);
                $done++;
            }
        }

        $this->clearSelection();
        $this->dispatch('notify', type: 'success', message: $done.' '.str('delivery')->plural($done).' sent through again.');
    }

    // -- Health --------------------------------------------------------------

    /**
     * Every source, with how it has been doing.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function health(): array
    {
        return DataSource::query()->orderBy('name')->get()
            ->map(fn (DataSource $source) => [
                'source' => $source,
                'stats' => IntegrationHealth::summarise($source),
            ])
            ->values()
            ->all();
    }

    public function alertThreshold(): int
    {
        return IntegrationHealth::ALERT_AFTER;
    }

    /**
     * Deliveries that arrived and are still waiting to be processed.
     *
     * Its own line above the per-source health, because it is not a fact about
     * any one source: when the worker stops, every source reads as quiet.
     *
     * @return array{count: int, oldest: Carbon|null}
     */
    #[Computed]
    public function stalled(): array
    {
        return IntegrationHealth::stalled();
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
        $this->sourceId = '';
        $this->clearFilters();
        $this->search = '';
    }

    public function updatedSourceId(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(): array
    {
        return IntegrationEventFields::sourceOptions();
    }

    /**
     * @return Collection<int, DataSource>
     */
    public function sources(): Collection
    {
        return DataSource::query()->orderBy('name')->get();
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
        return view('livewire.settings.integration-log');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
