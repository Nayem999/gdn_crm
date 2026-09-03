@props([
    // The Livewire component using WithDataView. It owns the state; this
    // component is only the chrome around it.
    'view',
    'records',
    'searchPlaceholder' => 'Search…',
    'emptyIcon' => 'inbox',
    'emptyHeading' => 'Nothing here yet',
    'emptyDescription' => null,
    'selectable' => true,
])

@php
    $columns = $view->pinnedThenLooseColumns();
    $modes = $view->availableViewModes();
    $chips = $view->activeFilterChips();
    $canExport = method_exists($view, 'canExport') && $view->canExport();
@endphp

<div class="space-y-4">
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-2 print:hidden">
        <div class="relative min-w-48 flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                <x-icon name="lucide-search" />
            </span>

            <input
                type="search"
                class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent/40"
                placeholder="{{ $searchPlaceholder }}"
                wire:model.live.debounce.400ms="search"
                aria-label="{{ $searchPlaceholder }}"
            />
        </div>

        @if (count($modes) > 1)
            <div class="inline-flex rounded-lg border border-border bg-card p-0.5" role="group" aria-label="View mode">
                @foreach ($modes as $mode)
                    <button
                        type="button"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors',
                            'bg-accent text-accent-foreground' => $view->viewMode === $mode->value,
                            'text-muted-foreground hover:text-foreground' => $view->viewMode !== $mode->value,
                        ])
                        wire:click="setViewMode('{{ $mode->value }}')"
                        aria-pressed="{{ $view->viewMode === $mode->value ? 'true' : 'false' }}"
                        title="{{ $mode->label() }} view"
                    >
                        <x-icon :name="'lucide-' . ($mode->icon())" />
                        <span class="sr-only">{{ $mode->label() }} view</span>
                    </button>
                @endforeach
            </div>
        @endif

        @if ($view->dataViewFilterFields() !== [])
            <x-filter-builder
                :fields="$view->filterFieldMap()"
                :filters="$view->filters"
                :count="$view->activeFilterCount()"
            />
        @endif

        @if ($view->currentViewMode() === \App\Domain\Shared\Enums\ViewMode::Table)
            <x-column-manager
                :columns="$view->dataViewColumns()"
                :visible="$view->visibleColumns"
                :pinned="$view->pinnedColumns"
            />
        @endif

        @if ($canExport)
            <x-export-menu :selection-count="count($view->selected)" />
        @endif

        @if (isset($actions))
            {{ $actions }}
        @endif
    </div>

    <x-filter-chips :chips="$chips" :search="$view->search" class="print:hidden" />

    {{-- Body: a skeleton shaped like the view being loaded, then the view. --}}
    <div wire:loading.delay.class="opacity-60">
        <div wire:loading.delay.remove>
            @if ($records->total() === 0)
                <x-empty-state
                    :icon="$emptyIcon"
                    :heading="$emptyHeading"
                    :description="$emptyDescription"
                    :filtered="$view->hasActiveFilters()"
                >
                    @if ($view->hasActiveFilters())
                        <x-slot:actions>
                            <x-button type="button" variant="secondary" wire:click="clearFilters">
                                <x-icon name="lucide-filter-x" />
                                Clear filters
                            </x-button>
                        </x-slot:actions>
                    @elseif (isset($emptyActions))
                        <x-slot:actions>{{ $emptyActions }}</x-slot:actions>
                    @endif
                </x-empty-state>
            @else
                @switch ($view->currentViewMode())
                    @case (\App\Domain\Shared\Enums\ViewMode::Kanban)
                        <x-data-view.kanban
                            :view="$view"
                            :records="$records"
                            :columns="$columns"
                            :board-columns="$view->dataViewKanbanColumns()"
                        />
                        @break

                    @case (\App\Domain\Shared\Enums\ViewMode::Grid)
                        <x-data-view.grid :view="$view" :records="$records" :columns="$columns" :selectable="$selectable" />
                        @break

                    @case (\App\Domain\Shared\Enums\ViewMode::List)
                        <x-data-view.list :view="$view" :records="$records" :columns="$columns" :selectable="$selectable" />
                        @break

                    @default
                        <x-data-view.table :view="$view" :records="$records" :columns="$columns" :selectable="$selectable" />
                @endswitch
            @endif
        </div>

        <div wire:loading.delay.flex class="hidden">
            <x-skeleton
                :shape="$view->currentViewMode()->skeleton()"
                :columns="max(count($columns), 2)"
                class="w-full"
            />
        </div>
    </div>

    {{-- Paging --}}
    @if ($records->total() > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <label for="{{ $view->dataViewModule() }}-per-page" class="sr-only">Rows per page</label>

                <select
                    id="{{ $view->dataViewModule() }}-per-page"
                    class="rounded-lg border border-border bg-background px-2 py-1 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-accent/40"
                    wire:change="setPerPage($event.target.value)"
                >
                    @foreach ([25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($view->perPage === $size)>{{ $size }}</option>
                    @endforeach
                </select>

                <span>per page &middot; {{ number_format($records->total()) }} total</span>
            </div>

            {{ $records->onEachSide(1)->links() }}
        </div>
    @endif

    @if ($selectable)
        <x-bulk-actions
            :count="$view->selectionCount()"
            :total="$records->total()"
            :show-select-all="! $view->selectAllMatching"
            class="print:hidden"
        >
            {{ $bulkActions ?? '' }}
        </x-bulk-actions>
    @endif
</div>
