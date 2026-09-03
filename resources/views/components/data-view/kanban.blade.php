@props(['view', 'records', 'columns', 'boardColumns' => [], 'selectable' => false])

@php
    $field = $view->dataViewKanbanField();
    $lead = $columns[0] ?? null;
    $details = array_slice($columns, 1, 2);

    // The board groups the page currently loaded, so paging still applies.
    $grouped = collect($records->items())->groupBy(fn ($record) => (string) $record->getAttribute($field));
@endphp

<div class="flex gap-4 overflow-x-auto pb-2">
    @foreach ($boardColumns as $boardColumn)
        @php $cards = $grouped->get($boardColumn['value'], collect()); @endphp

        <section
            class="flex w-72 shrink-0 flex-col rounded-xl border border-border bg-muted/30"
            wire:key="board-{{ $boardColumn['value'] }}"
            aria-label="{{ $boardColumn['label'] }}"
        >
            <header class="flex items-center justify-between gap-2 border-b border-border px-3 py-2.5">
                <span class="flex items-center gap-2 text-sm font-semibold text-foreground">
                    @if (($boardColumn['color'] ?? null) !== null)
                        <x-status-chip :color="$boardColumn['color']" dot>{{ $boardColumn['label'] }}</x-status-chip>
                    @else
                        {{ $boardColumn['label'] }}
                    @endif
                </span>

                <span class="text-xs text-muted-foreground">{{ $cards->count() }}</span>
            </header>

            <div
                class="flex min-h-24 flex-1 flex-col gap-2 p-2"
                x-data="kanbanColumn({ method: 'moveCard', value: @js($boardColumn['value']) })"
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
        </section>
    @endforeach
</div>
