@props(['view', 'records', 'columns', 'selectable' => true])

@php
    // A card leads with its first column and details the next few beneath.
    $lead = $columns[0] ?? null;
    $details = array_slice($columns, 1, 4);
@endphp

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($records as $record)
        <div
            class="rounded-xl border border-border bg-card p-4 transition-shadow hover:shadow-md"
            wire:key="card-{{ $record->getKey() }}"
        >
            <div class="flex items-start justify-between gap-3">
                @if ($lead)
                    <h3 class="truncate text-sm font-semibold text-foreground">
                        {{ $view->cellFor($record, $lead) }}
                    </h3>
                @endif

                @if ($selectable)
                    <input
                        type="checkbox"
                        class="mt-0.5 shrink-0 rounded border-border text-accent focus:ring-accent/40"
                        @checked(in_array($record->getKey(), $view->selected, true))
                        wire:click="toggleSelection({{ $record->getKey() }})"
                        aria-label="Select record {{ $record->getKey() }}"
                    />
                @endif
            </div>

            <dl class="mt-3 space-y-1.5">
                @foreach ($details as $column)
                    <div class="flex items-baseline justify-between gap-3 text-xs">
                        <dt class="shrink-0 text-muted-foreground">{{ $column->label }}</dt>
                        <dd class="truncate text-right text-foreground">{{ $view->cellFor($record, $column) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach
</div>
