@props(['view', 'records', 'columns', 'selectable' => true])

{{-- Horizontal scrolling lives on this wrapper so pinned cells can stick. --}}
<div class="overflow-x-auto rounded-xl border border-border">
    <table class="min-w-full divide-y divide-border text-sm">
        <thead class="bg-muted/50">
            <tr>
                @if ($selectable)
                    <th scope="col" class="w-10 px-3 py-2.5">
                        <input
                            type="checkbox"
                            class="rounded border-border text-accent focus:ring-accent/40"
                            @checked($view->pageIsFullySelected())
                            wire:click="togglePageSelection"
                            aria-label="Select all rows on this page"
                        />
                    </th>
                @endif

                @foreach ($columns as $column)
                    <th
                        scope="col"
                        @class([
                            'whitespace-nowrap px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground',
                            'text-right' => $column->numeric,
                            'text-left' => ! $column->numeric,
                            'sticky left-0 z-10 bg-muted/50' => $view->columnIsPinned($column->key),
                        ])
                        aria-sort="{{ $view->sortBy === $column->key ? ($view->sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}"
                    >
                        @if ($column->sortable)
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                                wire:click="sort('{{ $column->key }}')"
                            >
                                {{ $column->label }}

                                @if ($view->sortBy === $column->key)
                                    <x-icon :name="'lucide-' . ($view->sortDirection === 'asc' ? 'arrow-up' : 'arrow-down')" class="h-3 w-3" />
                                @else
                                    <x-icon name="lucide-chevrons-up-down" class="h-3 w-3 opacity-40" />
                                @endif
                            </button>
                        @else
                            {{ $column->label }}
                        @endif
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody class="divide-y divide-border bg-card">
            @foreach ($records as $record)
                <tr class="hover:bg-muted/40" wire:key="row-{{ $record->getKey() }}">
                    @if ($selectable)
                        <td class="px-3 py-2.5">
                            <input
                                type="checkbox"
                                class="rounded border-border text-accent focus:ring-accent/40"
                                @checked(in_array($record->getKey(), $view->selected, true))
                                wire:click="toggleSelection({{ $record->getKey() }})"
                                aria-label="Select row {{ $record->getKey() }}"
                            />
                        </td>
                    @endif

                    @foreach ($columns as $column)
                        <td @class([
                            'px-3 py-2.5 text-foreground',
                            'text-right tabular-nums' => $column->numeric,
                            'sticky left-0 z-10 bg-card' => $view->columnIsPinned($column->key),
                        ])>
                            {{ $view->cellFor($record, $column) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
