@php
    use App\Domain\Settings\NumberFormat;
@endphp

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
                <x-icon name="lucide-handshake" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Deals</h1>
                <p class="mt-1 text-sm text-muted-foreground">The business you are working, and what it is worth.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('deals.pipelines')
                <a
                    href="{{ route('settings.pipelines') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
                >
                    <x-icon name="lucide-git-branch" />
                    Pipelines
                </a>
            @endcan

            @can('create', App\Domain\Deals\Models\Deal::class)
                <a
                    href="{{ route('deals.create') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
                >
                    <x-icon name="lucide-plus" />
                    Add deal
                </a>
            @endcan
        </div>
    </div>

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:deal-deleted.window="tone = 'success'; message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
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

    {{-- What the current filters are worth. Its own aggregate over the whole
         filtered set, so it describes the data rather than the page. --}}
    @php($totals = $this->totals)
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Deals</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ number_format($totals['count']) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Total value</p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ NumberFormat::format($totals['value'], 0) }}</p>
        </div>
        <div class="rounded-xl border border-border bg-card px-4 py-3">
            <p class="text-xs uppercase tracking-wide text-muted-foreground" title="Each deal's value at its stage's probability">
                Weighted
            </p>
            <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">{{ NumberFormat::format($totals['weighted'], 0) }}</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
        {{-- The board only makes sense within one pipeline, so this is a hard
             scope in kanban mode rather than another filter chip.

             In that mode the placeholder names the pipeline the board is
             actually on rather than saying "All pipelines", which would be
             plainly untrue. The *value* is deliberately left empty instead of
             being set server-side: Tom Select sits behind wire:ignore and
             keeps its own selection, so a server-set value shows in the native
             select and not in the control the user is looking at. --}}
        <div class="w-full max-w-64" wire:key="pipeline-picker-{{ count($this->pipelineOptions()) }}">
            <x-select
                name="pipelineId"
                label="Pipeline"
                :options="$this->pipelineOptions()"
                :selected="$pipelineId"
                :placeholder="$viewMode === 'kanban'
                    ? ($this->boardPipeline()->name ?? 'Choose a pipeline…')
                    : 'All pipelines'"
                :clearable="$viewMode !== 'kanban'"
                :hint="$viewMode === 'kanban' ? 'A board shows the stages of one pipeline.' : null"
                wire:model.live="pipelineId"
            />
        </div>

        <div class="flex flex-wrap items-center gap-2 pb-1">
            @foreach ([
                'mine' => 'My deals',
                'open' => 'Still open',
                'won' => 'Won',
                'overdue' => 'Overdue',
                'closing_this_month' => 'Closing this month',
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
    </div>

    @if ($viewMode === 'kanban' && $this->boardPipeline() === null)
        <x-error-state
            heading="No pipeline to show a board for"
            description="A board needs a pipeline and its stages. Set one up first."
        />
    @else
        <x-data-view
            :view="$this"
            :records="$this->rows"
            search-placeholder="Search deals…"
            empty-icon="handshake"
            empty-heading="No deals yet"
            empty-description="Add the business you are working, or convert a qualified lead."
        >
            <x-slot:empty-actions>
                @can('create', App\Domain\Deals\Models\Deal::class)
                    <a href="{{ route('deals.create') }}" wire:navigate
                       class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                        <x-icon name="lucide-plus" />
                        Add your first deal
                    </a>
                @endcan
            </x-slot:empty-actions>

            <x-slot:bulk-actions>
                <button
                    type="button"
                    wire:click="deleteSelected"
                    wire:confirm="Remove the selected deals?"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-destructive hover:bg-destructive/10"
                >
                    <x-icon name="lucide-trash-2" class="h-4 w-4" />
                    Remove
                </button>
            </x-slot:bulk-actions>
        </x-data-view>
    @endif
</div>
