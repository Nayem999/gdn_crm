<div>
    <div
        x-data="{ message: '', tone: 'success' }"
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

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                <x-icon name="lucide-bar-chart-3" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Reports</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Saved questions. Each is answered from what you can see, so two people may get different numbers.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('reports.dashboard') }}" wire:navigate
           class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
            <x-icon name="lucide-layout-dashboard" class="h-4 w-4" />
            Dashboard
        </a>

        <a href="{{ route('reports.forecast') }}" wire:navigate
           class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
            <x-icon name="lucide-trending-up" class="h-4 w-4" />
            Forecast
        </a>

        @can('viewAny', App\Domain\Reports\Models\ReportSchedule::class)
            <a href="{{ route('reports.schedules') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                <x-icon name="lucide-mail-check" class="h-4 w-4" />
                Scheduled
            </a>
        @endcan

        @can('create', App\Domain\Reports\Models\Report::class)
            <a href="{{ route('reports.create') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                <x-icon name="lucide-plus" />
                New report
            </a>
        @endcan
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="relative min-w-[16rem] flex-1">
            <x-icon name="lucide-search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search reports…"
                   class="block w-full rounded-lg border border-border bg-background py-2 pl-10 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent">
        </div>

        <div class="w-48" wire:key="reports-source-filter">
            <x-select
                name="sourceFilter"
                :options="$this->sourceOptions()"
                :selected="$sourceFilter"
                placeholder="Anything"
                clearable
                wire:model.live="sourceFilter"
            />
        </div>

        @foreach (['mine' => 'Mine', 'shared' => 'Shared', 'standard' => 'Built in'] as $key => $label)
            <button type="button" wire:click="setScope('{{ $key }}')"
                    @class([
                        'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                        'border-accent bg-accent/10 text-accent' => $scope === $key,
                        'border-border text-muted-foreground hover:text-foreground' => $scope !== $key,
                    ])
                    aria-pressed="{{ $scope === $key ? 'true' : 'false' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($this->reports->isEmpty())
        <div class="rounded-xl border border-dashed border-border p-10 text-center">
            <x-icon name="lucide-bar-chart-3" class="mx-auto h-8 w-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold text-foreground">No reports here</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                Build one by choosing what to group by and what to measure.
            </p>
        </div>
    @else
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->reports as $report)
                <li class="flex flex-col rounded-xl border border-border bg-card p-4 transition-colors hover:border-accent/50">
                    <div class="flex items-start justify-between gap-2">
                        <a href="{{ route('reports.show', $report) }}" wire:navigate
                           class="text-base font-semibold text-foreground hover:text-accent hover:underline">
                            {{ $report->name }}
                        </a>
                        @if ($report->is_standard)
                            {!! \App\Domain\Shared\UI\ChipPalette::chip('Built in', 'slate') !!}
                        @elseif ($report->is_shared)
                            {!! \App\Domain\Shared\UI\ChipPalette::chip('Shared', 'emerald') !!}
                        @endif
                    </div>

                    @if ($report->description)
                        <p class="mt-1 flex-1 text-sm text-muted-foreground">{{ $report->description }}</p>
                    @endif

                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ $report->reportSource()?->label ?? $report->source }}
                        @if ($report->owner)
                            &middot; {{ $report->owner->name }}
                        @endif
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @can('create', App\Domain\Reports\Models\Report::class)
                            <button type="button" wire:click="duplicate({{ $report->id }})"
                                    class="rounded-lg border border-border px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                                Duplicate
                            </button>
                        @endcan
                        @can('update', $report)
                            <a href="{{ route('reports.edit', $report) }}" wire:navigate
                               class="rounded-lg border border-border px-2.5 py-1 text-xs font-medium text-foreground transition-colors hover:bg-muted">
                                Edit
                            </a>
                        @endcan
                        @can('delete', $report)
                            <button type="button" wire:click="delete({{ $report->id }})"
                                    wire:confirm="Remove this report?"
                                    class="rounded-lg border border-border px-2.5 py-1 text-xs font-medium text-destructive transition-colors hover:bg-destructive/10">
                                Remove
                            </button>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
