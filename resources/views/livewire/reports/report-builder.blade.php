<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('reports.index') }}" wire:navigate class="hover:text-foreground">Reports</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $this->isEditing() ? 'Editing' : 'A new report' }}</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">
                {{ $this->isEditing() ? 'Edit report' : 'Build a report' }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Drag a field in to group by it or measure it. The preview below is the report.
            </p>
        </div>

        <button type="button" wire:click="save"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
            <x-icon name="lucide-save" class="h-4 w-4" />
            Save report
        </button>
    </div>

    @error('measures') <x-alert variant="error" class="mb-4">{{ $message }}</x-alert> @enderror
    @error('source') <x-alert variant="error" class="mb-4">{{ $message }}</x-alert> @enderror
    @error('isShared') <x-alert variant="error" class="mb-4">{{ $message }}</x-alert> @enderror

    <div class="grid gap-6 lg:grid-cols-4">
        <aside class="space-y-4 lg:col-span-1">
            <div class="rounded-xl border border-border bg-card p-4">
                <div wire:key="report-source">
                    <x-select
                        name="source"
                        label="About"
                        :options="$this->sourceOptions()"
                        :selected="$source"
                        placeholder="Choose what to report on…"
                        wire:model.live="source"
                    />
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Group by
                </h2>
                @if ($this->availableDimensions() === [])
                    <p class="text-xs text-muted-foreground">Everything available is already in the report.</p>
                @else
                    <ul class="space-y-1">
                        @foreach ($this->availableDimensions() as $key => $label)
                            <li>
                                <button type="button" wire:click="addDimension('{{ $key }}')"
                                        class="flex w-full items-center justify-between gap-2 rounded-lg border border-dashed border-border px-3 py-1.5 text-left text-sm text-muted-foreground transition-colors hover:border-accent hover:text-accent">
                                    {{ $label }}
                                    <x-icon name="lucide-plus" class="h-3.5 w-3.5" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-border bg-card p-4">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Measure
                </h2>
                @if ($this->availableMeasures() === [])
                    <p class="text-xs text-muted-foreground">Everything available is already in the report.</p>
                @else
                    <ul class="space-y-1">
                        @foreach ($this->availableMeasures() as $key => $label)
                            <li>
                                <button type="button" wire:click="addMeasure('{{ $key }}')"
                                        class="flex w-full items-center justify-between gap-2 rounded-lg border border-dashed border-border px-3 py-1.5 text-left text-sm text-muted-foreground transition-colors hover:border-accent hover:text-accent">
                                    {{ $label }}
                                    <x-icon name="lucide-plus" class="h-3.5 w-3.5" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </aside>

        <div class="space-y-6 lg:col-span-3">
            <div class="grid gap-4 rounded-xl border border-border bg-card p-5 sm:grid-cols-2">
                <div>
                    <label for="report-name" class="mb-1 block text-sm font-medium text-foreground">Name</label>
                    <input id="report-name" type="text" wire:model="name"
                           class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                    @error('name') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="report-description" class="mb-1 block text-sm font-medium text-foreground">Description</label>
                    <input id="report-description" type="text" wire:model="description"
                           class="block w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent">
                    @error('description') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Drag to reorder: the order of the dimensions is the order of
                     the grouping and the columns, so it is a real decision
                     rather than a cosmetic one. --}}
                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Grouped by, in order
                    </h2>
                    @if ($this->chosenDimensions() === [])
                        <p class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                            Nothing yet — the report will be one row of totals.
                        </p>
                    @else
                        <ul
                            x-data="sortableList({ method: 'reorderDimensions' })"
                            wire:key="dimension-list"
                            class="space-y-1"
                        >
                            @foreach ($this->chosenDimensions() as $key => $label)
                                <li data-sortable-item data-sortable-id="{{ $key }}"
                                    wire:key="dim-{{ $key }}"
                                    class="flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/40 px-3 py-1.5 text-sm text-foreground">
                                    <span class="flex items-center gap-2">
                                        <span data-sortable-handle class="cursor-grab text-muted-foreground">
                                            <x-icon name="lucide-grip-vertical" class="h-4 w-4" />
                                        </span>
                                        {{ $label }}
                                    </span>
                                    <button type="button" wire:click="removeDimension('{{ $key }}')"
                                            class="text-muted-foreground transition-colors hover:text-destructive"
                                            aria-label="Remove {{ $label }}">
                                        <x-icon name="lucide-x" class="h-3.5 w-3.5" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-xl border border-border bg-card p-4">
                    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Measuring
                    </h2>
                    @if ($this->chosenMeasures() === [])
                        <p class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                            Choose at least one — a report with none is a list.
                        </p>
                    @else
                        <ul
                            x-data="sortableList({ method: 'reorderMeasures' })"
                            wire:key="measure-list"
                            class="space-y-1"
                        >
                            @foreach ($this->chosenMeasures() as $key => $label)
                                <li data-sortable-item data-sortable-id="{{ $key }}"
                                    wire:key="mea-{{ $key }}"
                                    class="flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/40 px-3 py-1.5 text-sm text-foreground">
                                    <span class="flex items-center gap-2">
                                        <span data-sortable-handle class="cursor-grab text-muted-foreground">
                                            <x-icon name="lucide-grip-vertical" class="h-4 w-4" />
                                        </span>
                                        {{ $label }}
                                    </span>
                                    <button type="button" wire:click="removeMeasure('{{ $key }}')"
                                            class="text-muted-foreground transition-colors hover:text-destructive"
                                            aria-label="Remove {{ $label }}">
                                        <x-icon name="lucide-x" class="h-3.5 w-3.5" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h2 class="mb-3 text-sm font-semibold text-foreground">Narrow it down</h2>
                <x-filter-builder
                    :fields="$this->filterFieldMap()"
                    :filters="$filters"
                    :count="$this->filterGroup()->count()"
                />
            </div>

            <div class="grid gap-4 rounded-xl border border-border bg-card p-5 sm:grid-cols-3">
                @if ($this->hasDateDimension())
                    <div wire:key="report-grain">
                        <x-select
                            name="grain"
                            label="Dates grouped by"
                            :options="$this->grainOptions()"
                            :selected="$grain"
                            placeholder="Choose…"
                            wire:model.live="grain"
                        />
                    </div>
                @endif

                <div wire:key="report-chart">
                    <x-select
                        name="chartType"
                        label="Shown as"
                        :options="$this->chartOptions()"
                        :selected="$chartType"
                        placeholder="Table"
                        wire:model.live="chartType"
                    />
                    @if ($this->chartWarning())
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ $this->chartWarning() }}</p>
                    @endif
                </div>

                @can('share', $this->report() ?? new App\Domain\Reports\Models\Report)
                    <label class="flex items-end gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="isShared"
                               class="h-4 w-4 rounded border-border text-accent focus:ring-accent">
                        Everybody can see this
                    </label>
                @endcan
            </div>

            {{-- The skeleton is shaped like the chart that is coming, so the
                 page does not jump when the figures arrive. --}}
            <div wire:loading.delay class="w-full">
                <x-chart.skeleton :type="$chartType" />
            </div>

            <div wire:loading.remove.delay class="space-y-6">
                @if ($this->chart() !== \App\Domain\Reports\Enums\ChartType::Table)
                    <x-report-chart :result="$this->result" :type="$this->chart()" />
                @endif

                <x-report-table :result="$this->result" :sortable="true" />
            </div>
        </div>
    </div>
</div>
