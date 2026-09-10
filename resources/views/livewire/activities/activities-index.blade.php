<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300">
                <x-icon name="lucide-calendar-clock" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Activities</h1>
                <p class="mt-1 text-sm text-muted-foreground">Tasks, calls and meetings — soonest first.</p>
            </div>
        </div>

        @can('create', App\Domain\Activities\Models\Activity::class)
            <a
                href="{{ route('activities.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                Add activity
            </a>
        @endcan
    </div>

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:activity-deleted.window="tone = 'success'; message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
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

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    {{-- What the current filters contain. Its own aggregate over the whole
         filtered set, so it describes the data rather than the page. --}}
    @php($totals = $this->totals)
    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Activities</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ number_format($totals['count']) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Still open</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ number_format($totals['open']) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Overdue</p>
            <p @class([
                'mt-0.5 text-xl font-semibold tabular-nums',
                'text-destructive' => $totals['overdue'] > 0,
                'text-foreground' => $totals['overdue'] === 0,
            ])>{{ number_format($totals['overdue']) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Completed</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ number_format($totals['completed']) }}</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach ([
            'mine' => 'Mine',
            'today' => 'Due today',
            'overdue' => 'Overdue',
            'this_week' => 'This week',
            'completed' => 'Completed',
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
        search-placeholder="Search activities…"
        empty-icon="calendar-clock"
        empty-heading="Nothing scheduled"
        empty-description="Add the calls, meetings and tasks you owe people, and they stop living in your head."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Activities\Models\Activity::class)
                <a href="{{ route('activities.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Add your first activity
                </a>
            @endcan
        </x-slot:empty-actions>

        <x-slot:bulk-actions>
            @if ($this->canAct)
                <button
                    type="button"
                    wire:click="completeSelected"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                >
                    <x-icon name="lucide-check" class="h-4 w-4" />
                    Mark done
                </button>
            @endif

            <button
                type="button"
                wire:click="deleteSelected"
                wire:confirm="Remove the selected activities?"
                class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-destructive hover:bg-destructive/10"
            >
                <x-icon name="lucide-trash-2" class="h-4 w-4" />
                Remove
            </button>
        </x-slot:bulk-actions>
    </x-data-view>
</div>
