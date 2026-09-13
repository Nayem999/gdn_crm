<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300">
                <x-icon name="lucide-life-buoy" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Support</h1>
                <p class="mt-1 text-sm text-muted-foreground">What customers are waiting on — most urgent first, then longest waiting.</p>
            </div>
        </div>

        @can('create', App\Domain\Support\Models\Ticket::class)
            <a
                href="{{ route('tickets.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                New ticket
            </a>
        @endcan
    </div>

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:ticket-deleted.window="tone = 'success'; message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
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

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @php($totals = $this->totals)
    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Tickets</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ number_format($totals['count']) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Still open</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ number_format($totals['open']) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">High or urgent</p>
            <p @class([
                'mt-0.5 text-xl font-semibold tabular-nums',
                'text-destructive' => $totals['urgent'] > 0,
                'text-foreground' => $totals['urgent'] === 0,
            ])>{{ number_format($totals['urgent']) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Resolved</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ number_format($totals['resolved']) }}</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach ([
            'mine' => 'Mine',
            'open' => 'Still open',
            'urgent' => 'High or urgent',
            'unlinked' => 'No customer attached',
            'resolved' => 'Resolved',
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
            >{{ $label }}</button>
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
        search-placeholder="Search tickets…"
        empty-icon="life-buoy"
        empty-heading="Nothing is waiting"
        empty-description="Raise a ticket when a customer reports a problem, so it does not live in somebody's inbox."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Support\Models\Ticket::class)
                <a href="{{ route('tickets.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Raise the first ticket
                </a>
            @endcan
        </x-slot:empty-actions>

        <x-slot:bulk-actions>
            <button
                type="button"
                wire:click="deleteSelected"
                wire:confirm="Remove the selected tickets? What was said on them is kept."
                class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-destructive hover:bg-destructive/10"
            >
                <x-icon name="lucide-trash-2" class="h-4 w-4" />
                Remove
            </button>
        </x-slot:bulk-actions>
    </x-data-view>
</div>
