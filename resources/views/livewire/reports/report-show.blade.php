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

    {{-- The period: a reader's choice, carried in the URL, never saved over the
         report. The dropdowns never change their own options, so they need no
         moving wire:key. --}}
    <section class="mb-6 rounded-xl border border-border bg-card p-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-select
                name="period"
                label="Period"
                :options="$periods"
                :selected="$this->activePeriod()->value"
                wire:model.live="period"
            />

            @if (count($this->dateFieldOptions()) > 1)
                <x-select
                    name="date_field"
                    label="Measured on"
                    :options="$this->dateFieldOptions()"
                    :selected="$this->activeDateField()"
                    hint="Which date the period applies to."
                    wire:model.live="dateField"
                />
            @endif

            @if ($this->activePeriod() === \App\Domain\Reports\Enums\DatePeriod::Custom)
                <div>
                    <x-form.label for="report-from">From</x-form.label>
                    <x-form.input id="report-from" type="date" wire:model.live.debounce.500ms="from" />
                </div>
                <div>
                    <x-form.label for="report-to">To</x-form.label>
                    <x-form.input id="report-to" type="date" wire:model.live.debounce.500ms="to" />
                </div>
            @endif
        </div>

        @if ($summary = $this->periodSummary())
            <p class="mt-3 text-xs text-muted-foreground">
                {{ $summary }}, by {{ strtolower($this->dateFieldOptions()[$this->activeDateField()] ?? 'date') }}.
                The link in your address bar opens exactly this view.
            </p>
        @endif
    </section>

    <div wire:loading.delay class="w-full">
        <x-chart.skeleton :type="$report->chart_type" />
    </div>

    <div wire:loading.remove.delay class="space-y-6">
        @if ($records = $this->records)
            {{-- The records behind one row: what "why is this at the top?" is
                 answered with. Drawn through the same scoped query, so they add
                 up to the row. --}}
            <section class="rounded-xl border border-border bg-card">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
                    <div>
                        <h2 class="text-base font-semibold text-foreground">
                            {{ $records->source?->label ?? 'Records' }} behind this row
                        </h2>
                        <p class="text-sm text-muted-foreground">{{ $this->drillSummary() }}</p>
                    </div>
                    <x-button type="button" variant="secondary" wire:click="clearDrill">
                        <x-icon name="lucide-arrow-left" class="h-4 w-4" />
                        Back to the report
                    </x-button>
                </div>

                @if ($records->refused)
                    <p class="px-4 py-6 text-sm text-muted-foreground">These records are not yours to read.</p>
                @elseif ($records->items === [])
                    <p class="px-4 py-6 text-sm text-muted-foreground">No records fall into this row any more.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th class="px-4 py-2.5 font-medium">{{ \Illuminate\Support\Str::singular($records->source?->label ?? 'Record') }}</th>
                                    @foreach ($records->measures as $measure)
                                        <th class="px-4 py-2.5 text-right font-medium">{{ $measure->label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($records->items as $item)
                                    <tr class="border-b border-border/60 last:border-0" wire:key="drill-{{ $item['id'] }}">
                                        <td class="px-4 py-2">
                                            @if ($item['url'])
                                                <a href="{{ $item['url'] }}" wire:navigate class="font-medium text-accent hover:underline">{{ $item['label'] }}</a>
                                            @else
                                                {{ $item['label'] }}
                                            @endif
                                        </td>
                                        @foreach ($records->measures as $key => $measure)
                                            @php($value = $item['values'][$key] ?? null)
                                            <td class="px-4 py-2 text-right tabular-nums text-foreground">
                                                @if ($value === null)
                                                    —
                                                @elseif ($measure->format === 'money')
                                                    {{ number_format((float) $value, 2) }}
                                                @elseif ($measure->format === 'percent')
                                                    {{ number_format((float) $value, 1) }}%
                                                @else
                                                    {{ is_float($value) && floor($value) !== $value ? number_format($value, 2) : number_format((float) $value) }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($records->truncated)
                        <p class="border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                            Showing the first {{ \App\Domain\Reports\ReportRecords::LIMIT }}. Narrow the period to see the rest.
                        </p>
                    @endif
                @endif
            </section>
        @else
            @if ($this->chart() !== \App\Domain\Reports\Enums\ChartType::Table)
                <x-report-chart :result="$this->result" :type="$this->chart()" />
            @endif

            <x-report-table :result="$this->result" :links="$this->links" drillable />
        @endif
    </div>
</div>
