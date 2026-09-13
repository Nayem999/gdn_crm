<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                <x-icon name="lucide-layout-dashboard" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">KPI dashboard</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Your own arrangement. Every widget is answered from what you can see, so these are your figures.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('reports.index') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                <x-icon name="lucide-bar-chart-3" class="h-4 w-4" />
                Reports
            </a>

            <button type="button" wire:click="toggleArranging"
                    @class([
                        'inline-flex items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors',
                        'border-accent bg-accent/10 text-accent' => $arranging,
                        'border-border bg-card text-foreground hover:bg-muted' => ! $arranging,
                    ])
                    aria-pressed="{{ $arranging ? 'true' : 'false' }}">
                <x-icon name="lucide-{{ $arranging ? 'check' : 'settings-2' }}" class="h-4 w-4" />
                {{ $arranging ? 'Done' : 'Arrange' }}
            </button>
        </div>
    </div>

    @if ($arranging)
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-border bg-card p-4">
            <div class="min-w-[16rem] flex-1" wire:key="widget-add">
                <x-select
                    name="addReportId"
                    label="Add a report"
                    :options="$this->addableReports()"
                    :selected="$addReportId"
                    placeholder="{{ $this->addableReports() === [] ? 'Nothing left to add' : 'Choose a report…' }}"
                    wire:model="addReportId"
                />
            </div>

            <button type="button" wire:click="add"
                    @disabled($this->isFull())
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90 disabled:opacity-50">
                <x-icon name="lucide-plus" />
                Add
            </button>

            @if ($this->isFull())
                <p class="text-xs text-muted-foreground">
                    A dashboard holds {{ \App\Livewire\Reports\KpiDashboard::MAX_WIDGETS }} widgets. Each one is a query.
                </p>
            @endif
        </div>
    @endif

    @if ($this->widgets->isEmpty())
        <div class="rounded-xl border border-dashed border-border p-10 text-center">
            <x-icon name="lucide-layout-dashboard" class="mx-auto h-8 w-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold text-foreground">Nothing here yet</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                Press Arrange and add a report. Anything you can open in Reports can sit here.
            </p>
        </div>
    @else
        <div
            x-data="sortableList({ method: 'reorder', handle: '[data-sortable-handle]' })"
            wire:key="widget-grid"
            class="grid grid-cols-1 gap-4 lg:grid-cols-3"
        >
            @foreach ($this->widgets as $widget)
                @php($result = $this->resultFor($widget))
                <section
                    data-sortable-item
                    data-sortable-id="{{ $widget->id }}"
                    wire:key="widget-{{ $widget->id }}"
                    @class([
                        'rounded-xl border border-border bg-card p-5',
                        'lg:col-span-1' => $widget->span() === 1,
                        'lg:col-span-2' => $widget->span() === 2,
                        'lg:col-span-3' => $widget->span() === 3,
                    ])
                >
                    <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                            @if ($arranging)
                                <span data-sortable-handle class="cursor-grab text-muted-foreground">
                                    <x-icon name="lucide-grip-vertical" class="h-4 w-4" />
                                </span>
                            @endif
                            <a href="{{ route('reports.show', $widget->report_id) }}" wire:navigate
                               class="hover:text-accent hover:underline">
                                {{ $widget->heading() }}
                            </a>
                        </h2>

                        @if ($arranging)
                            <div class="flex items-center gap-1">
                                @foreach ([1, 2, 3] as $width)
                                    <button type="button" wire:click="resize({{ $widget->id }}, {{ $width }})"
                                            @class([
                                                'rounded border px-1.5 py-0.5 text-[10px] font-medium transition-colors',
                                                'border-accent bg-accent/10 text-accent' => $widget->span() === $width,
                                                'border-border text-muted-foreground hover:text-foreground' => $widget->span() !== $width,
                                            ])
                                            aria-label="{{ $width }} column{{ $width === 1 ? '' : 's' }} wide">
                                        {{ $width }}
                                    </button>
                                @endforeach

                                <button type="button" wire:click="remove({{ $widget->id }})"
                                        class="ml-1 text-muted-foreground transition-colors hover:text-destructive"
                                        aria-label="Remove {{ $widget->heading() }}">
                                    <x-icon name="lucide-x" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        @endif
                    </div>

                    @if ($arranging)
                        <div class="mb-3" wire:key="widget-chart-{{ $widget->id }}">
                            <x-select
                                name="widget-chart-{{ $widget->id }}"
                                :options="$this->chartOptions()"
                                :selected="$widget->chart_type"
                                placeholder="However the report says"
                                clearable
                                wire:change="setChart({{ $widget->id }}, $event.target.value)"
                            />
                        </div>
                    @endif

                    <div wire:loading.delay wire:target="reorder,resize,setChart,add,remove">
                        <x-chart.skeleton :type="$widget->chart()->value" class="border-0 p-0" />
                    </div>

                    <div wire:loading.remove.delay wire:target="reorder,resize,setChart,add,remove">
                        @if ($widget->chart() === \App\Domain\Reports\Enums\ChartType::Table)
                            <x-report-table :result="$result" />
                        @else
                            <x-report-chart :result="$result" :type="$widget->chart()" />
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
