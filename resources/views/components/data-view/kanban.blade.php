@props(['view', 'columns', 'boardColumns' => []])

@php
    use App\Domain\Settings\NumberFormat;

    $lead = $columns[0] ?? null;

    // The field the board groups by is never a card detail: every card in the
    // "Scoping" column would otherwise carry the line "Stage: Scoping", which
    // is the column header repeated on each of its own cards.
    $groupedBy = $view->dataViewKanbanField();

    $details = array_slice(
        array_values(array_filter(
            array_slice($columns, 1),
            fn ($column) => $column->key !== $groupedBy
        )),
        0,
        2
    );

    // Counts and totals come from one grouped query over the whole filtered
    // set, not from the loaded cards: a board built out of one page shows an
    // arbitrary slice of each column and counts that are simply wrong.
    $totals = $view->kanbanTotals();
@endphp

<div class="flex gap-4 overflow-x-auto pb-2">
    @foreach ($boardColumns as $boardColumn)
        @php
            $value = $boardColumn['value'];
            $cards = $view->kanbanCards($value);
            $total = $totals[$value] ?? ['count' => 0, 'sum' => null];
        @endphp

        <section
            class="flex w-72 shrink-0 flex-col rounded-xl border border-border bg-muted/30"
            wire:key="board-{{ $value }}"
            aria-label="{{ $boardColumn['label'] }}"
            data-board-column="{{ $value }}"
        >
            <header class="border-b border-border px-3 py-2.5">
                <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2 text-sm font-semibold text-foreground">
                        @if (($boardColumn['color'] ?? null) !== null)
                            <x-status-chip :color="$boardColumn['color']" dot>{{ $boardColumn['label'] }}</x-status-chip>
                        @else
                            {{ $boardColumn['label'] }}
                        @endif
                    </span>

                    {{-- data-board-count is what the drag nudges while the
                         move is in flight; the server's re-render then sets it
                         back from the grouped query. --}}
                    <span
                        class="text-xs text-muted-foreground"
                        data-board-count="{{ $total['count'] }}"
                    >{{ number_format($total['count']) }}</span>
                </div>

                @if ($total['sum'] !== null)
                    <p class="mt-1 text-xs tabular-nums text-muted-foreground">
                        {{ NumberFormat::format($total['sum'], 0) }}
                    </p>
                @endif
            </header>

            <div
                class="flex min-h-24 flex-1 flex-col gap-2 p-2"
                x-data="kanbanColumn({ method: 'moveCard', value: @js($value) })"
                wire:ignore.self
            >
                @foreach ($cards as $record)
                    <article
                        class="cursor-grab rounded-lg border border-border bg-card p-3 shadow-sm"
                        data-card-id="{{ $record->getKey() }}"
                        wire:key="kanban-card-{{ $record->getKey() }}"
                    >
                        @if ($lead)
                            <p class="truncate text-sm font-medium text-foreground">{{ $view->cellFor($record, $lead) }}</p>
                        @endif

                        @foreach ($details as $column)
                            <p class="mt-1 truncate text-xs text-muted-foreground">
                                {{ $column->label }}: {{ $view->cellFor($record, $column) }}
                            </p>
                        @endforeach
                    </article>
                @endforeach

                @if ($cards->isEmpty())
                    <p class="px-1 py-3 text-center text-xs text-muted-foreground">Drop cards here</p>
                @endif
            </div>

            @if ($view->hasMoreKanbanCards($value))
                <footer class="border-t border-border p-2">
                    <button
                        type="button"
                        wire:click="loadMoreKanban(@js($value))"
                        wire:loading.attr="disabled"
                        class="w-full rounded-lg px-2 py-1.5 text-xs font-medium text-accent hover:bg-accent/10"
                    >
                        Load more
                        <span class="text-muted-foreground">
                            ({{ number_format($total['count'] - $cards->count()) }} left)
                        </span>
                    </button>
                </footer>
            @endif
        </section>
    @endforeach
</div>
