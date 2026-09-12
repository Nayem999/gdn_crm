<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-cyan-100 text-cyan-600 dark:bg-cyan-500/15 dark:text-cyan-300">
                <x-icon name="lucide-file-text" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Quotes</h1>
                <p class="mt-1 text-sm text-muted-foreground">What you have offered, and what came of it.</p>
            </div>
        </div>

        @can('create', App\Domain\Sales\Models\Quote::class)
            <a
                href="{{ route('quotes.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                New quote
            </a>
        @endcan
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach (['mine' => 'Mine', 'open' => 'Still open', 'accepted' => 'Accepted'] as $chip => $label)
            <button
                type="button"
                wire:click="setQuickFilter('{{ $chip }}')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                    'border-accent bg-accent/10 text-accent' => $quickFilter === $chip,
                    'border-border text-muted-foreground hover:text-foreground' => $quickFilter !== $chip,
                ])
                aria-pressed="{{ $quickFilter === $chip ? 'true' : 'false' }}"
            >{{ $label }}</button>
        @endforeach

        {{-- Superseded versions are history. Off by default, because a list
             where the same quote appears three times is not a list. --}}
        <label class="ml-2 flex items-center gap-2 text-xs text-muted-foreground">
            <input type="checkbox" wire:model.live="includeSuperseded" class="rounded border-border text-accent focus:ring-accent/40" />
            Show superseded versions
        </label>

        @if ($quickFilter !== '' || $this->hasActiveFilters())
            <button type="button" wire:click="clearAllFilters" class="text-xs font-medium text-muted-foreground hover:text-destructive">
                Clear all
            </button>
        @endif
    </div>

    <x-data-view
        :view="$this"
        :records="$this->rows"
        search-placeholder="Search quotes…"
        empty-icon="file-text"
        empty-heading="No quotes yet"
        empty-description="Build one from the catalogue, send it, and track what happens."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Sales\Models\Quote::class)
                <a href="{{ route('quotes.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Build your first quote
                </a>
            @endcan
        </x-slot:empty-actions>
    </x-data-view>
</div>
