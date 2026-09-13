<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('reports.index') }}" wire:navigate class="hover:text-foreground">Reports</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $report->name }}</span>
    </nav>

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">{{ $report->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                @if ($report->description)
                    <span>{{ $report->description }}</span>
                    <span>&middot;</span>
                @endif
                <span>{{ $report->reportSource()?->label ?? $report->source }}</span>
                @if ($report->is_standard)
                    {!! \App\Domain\Shared\UI\ChipPalette::chip('Built in', 'slate') !!}
                @elseif ($report->is_shared)
                    {!! \App\Domain\Shared\UI\ChipPalette::chip('Shared', 'emerald') !!}
                @endif
            </p>
        </div>

        @if ($this->canEdit())
            <a href="{{ route('reports.edit', $report) }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                <x-icon name="lucide-pencil" class="h-4 w-4" />
                Edit
            </a>
        @endif
    </div>

    <div wire:loading.delay class="w-full">
        <x-chart.skeleton :type="$report->chart_type" />
    </div>

    <div wire:loading.remove.delay class="space-y-6">
        @if ($this->chart() !== \App\Domain\Reports\Enums\ChartType::Table)
            <x-report-chart :result="$this->result" :type="$this->chart()" />
        @endif

        <x-report-table :result="$this->result" />
    </div>
</div>
