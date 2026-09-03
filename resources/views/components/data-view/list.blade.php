@props(['view', 'records', 'columns', 'selectable' => true])

@php
    $lead = $columns[0] ?? null;
    $details = array_slice($columns, 1, 3);
@endphp

{{-- One dense row per record: comfortable on narrow screens where a table
     would need side-scrolling. --}}
<ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
    @foreach ($records as $record)
        <li class="flex items-center gap-3 px-4 py-3 hover:bg-muted/40" wire:key="list-{{ $record->getKey() }}">
            @if ($selectable)
                <input
                    type="checkbox"
                    class="shrink-0 rounded border-border text-accent focus:ring-accent/40"
                    @checked(in_array($record->getKey(), $view->selected, true))
                    wire:click="toggleSelection({{ $record->getKey() }})"
                    aria-label="Select record {{ $record->getKey() }}"
                />
            @endif

            <div class="min-w-0 flex-1">
                @if ($lead)
                    <p class="truncate text-sm font-medium text-foreground">{{ $view->cellFor($record, $lead) }}</p>
                @endif

                @if ($details !== [])
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        @foreach ($details as $index => $column)
                            <span>{{ $column->label }}: {{ $view->cellFor($record, $column) }}</span>
                            @if ($index < count($details) - 1)
                                <span aria-hidden="true"> &middot; </span>
                            @endif
                        @endforeach
                    </p>
                @endif
            </div>
        </li>
    @endforeach
</ul>
