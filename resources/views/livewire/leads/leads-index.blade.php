<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300">
                <x-icon name="lucide-target" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Leads</h1>
                <p class="mt-1 text-sm text-muted-foreground">People who might become customers, and where each has got to.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('leads.import')
                <a
                    href="{{ route('imports.create', ['module' => 'leads']) }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
                >
                    <x-icon name="lucide-upload" />
                    Import
                </a>
            @endcan

            @can('create', App\Domain\Leads\Models\Lead::class)
                <a
                    href="{{ route('leads.create') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
                >
                    <x-icon name="lucide-plus" />
                    Capture lead
                </a>
            @endcan
        </div>
    </div>

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:lead-deleted.window="tone = 'success'; message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
        x-on:lead-updated.window="tone = 'success'; message = $event.detail.message; setTimeout(() => message = '', 3000)"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 5000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <template x-if="tone === 'error'">
            <x-alert variant="error"><span x-text="message"></span></x-alert>
        </template>
        <template x-if="tone !== 'error'">
            <x-alert variant="success"><span x-text="message"></span></x-alert>
        </template>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach ([
            'mine' => 'My leads',
            'open' => 'Still open',
            'stalled' => 'Untouched 14+ days',
            'this_week' => 'Captured this week',
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
        search-placeholder="Search leads…"
        empty-icon="target"
        empty-heading="No leads yet"
        empty-description="Capture the people who have shown an interest, and work them through the pipeline."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Leads\Models\Lead::class)
                <a href="{{ route('leads.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Capture your first lead
                </a>
            @endcan
        </x-slot:empty-actions>

        <x-slot:bulk-actions>
            @can('create', App\Domain\Leads\Models\Lead::class)
                <button
                    type="button"
                    wire:click="deleteSelected"
                    wire:confirm="Remove the selected leads?"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-destructive hover:bg-destructive/10"
                >
                    <x-icon name="lucide-trash-2" class="h-4 w-4" />
                    Remove
                </button>
            @endcan
        </x-slot:bulk-actions>
    </x-data-view>
</div>
