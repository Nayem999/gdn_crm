<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300">
                <x-icon name="lucide-megaphone" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Campaigns</h1>
                <p class="mt-1 text-sm text-muted-foreground">What marketing costs, and what it brought in.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('create', App\Domain\Campaigns\Models\Campaign::class)
                <a
                    href="{{ route('campaigns.create') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
                >
                    <x-icon name="lucide-plus" />
                    Add campaign
                </a>
            @endcan
        </div>
    </div>

    {{-- Quick filter chips, kept beside the builder rather than inside it so
         one cannot silently overwrite the other. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach ([
            'running' => 'Running now',
            'planned' => 'Planned',
            'overspent' => 'Over budget',
            'mine' => 'Mine',
        ] as $chip => $label)
            <button
                type="button"
                wire:click="setQuickFilter('{{ $chip }}')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                    'border-accent bg-accent/10 text-accent' => $quickFilter === $chip,
                    'border-border text-muted-foreground hover:text-foreground' => $quickFilter !== $chip,
                ])
                aria-pressed="{{ $quickFilter === $chip ? 'true' : 'false' }}"
            >
                {{ $label }}
            </button>
        @endforeach

        @if ($quickFilter !== '' || $this->hasActiveFilters())
            <button type="button" wire:click="clearAllFilters" class="text-xs font-medium text-muted-foreground hover:text-destructive">
                Clear all
            </button>
        @endif
    </div>

    <x-data-view
        :view="$this"
        :records="$this->rows"
        search-placeholder="Search campaigns…"
        empty-icon="megaphone"
        empty-heading="No campaigns yet"
        empty-description="Add the campaigns you run — advertising, email, events — and every lead they bring in can be attributed to one."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Campaigns\Models\Campaign::class)
                <a href="{{ route('campaigns.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Add your first campaign
                </a>
            @endcan
        </x-slot:empty-actions>
    </x-data-view>
</div>
