@props(['selectionCount' => 0])

<div x-data="{ open: false }" class="relative" @keydown.escape="open = false">
    <button
        type="button"
        class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted focus:outline-none focus:ring-2 focus:ring-accent/40"
        x-on:click="open = ! open"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="true"
    >
        <x-icon name="lucide-download" />
        <span class="hidden sm:inline">Export</span>
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        x-transition.origin.top.right
        class="absolute right-0 z-30 mt-2 w-64 rounded-xl border border-border bg-card p-2 shadow-lg"
        role="menu"
    >
        @if ($selectionCount > 0)
            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-foreground hover:bg-muted">
                <input
                    type="checkbox"
                    class="rounded border-border text-accent focus:ring-accent/40"
                    wire:model.live="exportSelectedOnly"
                />
                Selected {{ \Illuminate\Support\Str::plural('row', $selectionCount) }} only
                ({{ number_format($selectionCount) }})
            </label>

            <div class="my-1 h-px bg-border" role="separator"></div>
        @endif

        @foreach (\App\Domain\Shared\Enums\ExportFormat::cases() as $format)
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-foreground hover:bg-muted"
                wire:click="export('{{ $format->value }}')"
                x-on:click="open = false"
                role="menuitem"
            >
                <x-icon :name="'lucide-' . ($format->icon())" />
                Download as {{ $format->label() }}
            </button>
        @endforeach

        <div class="my-1 h-px bg-border" role="separator"></div>

        <button
            type="button"
            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-foreground hover:bg-muted"
            x-on:click="open = false; window.print()"
            role="menuitem"
        >
            <x-icon name="lucide-printer" />
            Print
        </button>

        <p class="px-2 pt-1.5 text-xs text-muted-foreground">
            Exports follow your current filters, sort order and visible columns.
        </p>
    </div>
</div>
