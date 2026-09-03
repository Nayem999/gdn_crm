@props(['columns', 'visible' => [], 'pinned' => []])

@php
    /** @var array<int, \App\Domain\Shared\DataView\Column> $ordered */
    // Visible columns first, in the user's own order, then whatever is switched
    // off — so dragging always operates on what the user can actually see.
    $byKey = collect($columns)->keyBy('key');
    $ordered = collect($visible)
        ->map(fn (string $key) => $byKey->get($key))
        ->filter()
        ->concat(collect($columns)->reject(fn ($column) => in_array($column->key, $visible, true)))
        ->values();
@endphp

<div x-data="{ open: false }" class="relative" @keydown.escape="open = false">
    <button
        type="button"
        class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted focus:outline-none focus:ring-2 focus:ring-accent/40"
        x-on:click="open = ! open"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="true"
    >
        <x-icon name="lucide-columns-3" />
        <span class="hidden sm:inline">Columns</span>
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-transition.origin.top.right
        class="absolute right-0 z-30 mt-2 w-72 rounded-xl border border-border bg-card p-2 shadow-lg"
        role="dialog"
        aria-label="Manage columns"
    >
        <div class="flex items-center justify-between px-2 py-1.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Columns</span>
            <button type="button" class="text-xs font-medium text-accent hover:underline" wire:click="resetColumns">
                Reset
            </button>
        </div>

        <ul
            class="max-h-80 space-y-0.5 overflow-y-auto"
            x-data="sortableList({ method: 'reorderColumns' })"
            wire:ignore.self
        >
            @foreach ($ordered as $column)
                <li
                    class="flex items-center gap-1.5 rounded-lg px-1.5 py-1 hover:bg-muted"
                    data-sortable-item
                    data-sortable-id="{{ $column->key }}"
                    wire:key="column-manager-{{ $column->key }}"
                >
                    @if ($column->locked)
                        <span class="w-4" aria-hidden="true"></span>
                    @else
                        <button
                            type="button"
                            class="cursor-grab rounded p-0.5 text-muted-foreground hover:text-foreground"
                            data-sortable-handle
                            aria-label="Reorder {{ $column->label }}"
                        >
                            <x-icon name="lucide-grip-vertical" class="h-4 w-4" />
                        </button>
                    @endif

                    <label class="flex flex-1 items-center gap-2 text-sm text-foreground {{ $column->locked ? 'opacity-60' : 'cursor-pointer' }}">
                        <input
                            type="checkbox"
                            class="rounded border-border text-accent focus:ring-accent/40"
                            @checked(in_array($column->key, $visible, true))
                            @disabled($column->locked)
                            wire:click="toggleColumn('{{ $column->key }}')"
                        />
                        {{ $column->label }}
                    </label>

                    <button
                        type="button"
                        class="rounded p-1 {{ in_array($column->key, $pinned, true) ? 'text-accent' : 'text-muted-foreground hover:text-foreground' }}"
                        wire:click="togglePin('{{ $column->key }}')"
                        aria-pressed="{{ in_array($column->key, $pinned, true) ? 'true' : 'false' }}"
                        aria-label="{{ in_array($column->key, $pinned, true) ? 'Unpin' : 'Pin' }} {{ $column->label }}"
                        title="Pin left"
                    >
                        <x-icon name="lucide-pin" class="h-3.5 w-3.5" />
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</div>
