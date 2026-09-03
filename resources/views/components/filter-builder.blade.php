@props(['fields', 'filters', 'count' => 0])

@php
    use App\Domain\Shared\Filters\FilterGroup;

    $joinClass = 'rounded-lg border border-border bg-background px-2 py-1 text-xs font-semibold text-foreground focus:outline-none focus:ring-1 focus:ring-accent/40';
@endphp

<div x-data="{ open: @js($count > 0) }" class="relative">
    <button
        type="button"
        class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted focus:outline-none focus:ring-2 focus:ring-accent/40"
        x-on:click="open = ! open"
        :aria-expanded="open ? 'true' : 'false'"
    >
        <x-icon name="lucide-filter" />
        <span class="hidden sm:inline">Filters</span>

        @if ($count > 0)
            <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1.5 text-xs font-semibold text-accent-foreground">
                {{ $count }}
            </span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.origin.top.left
        class="absolute left-0 z-30 mt-2 w-[min(46rem,calc(100vw-2rem))] rounded-xl border border-border bg-card p-4 shadow-lg"
        role="dialog"
        aria-label="Build filters"
    >
        @if ($fields === [])
            <p class="text-sm text-muted-foreground">This list has no filterable fields.</p>
        @else
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Match</span>

                <select class="{{ $joinClass }}" wire:model.live="filters.match" aria-label="Match all or any condition">
                    <option value="{{ FilterGroup::MATCH_ALL }}">all conditions</option>
                    <option value="{{ FilterGroup::MATCH_ANY }}">any condition</option>
                </select>
            </div>

            <div class="mt-3 space-y-2">
                @forelse ($filters['conditions'] ?? [] as $index => $condition)
                    <div wire:key="filter-condition-{{ $index }}">
                        <x-filter-builder.condition
                            :condition="$condition"
                            :index="$index"
                            :fields="$fields"
                        />
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground">No conditions yet.</p>
                @endforelse
            </div>

            {{-- Nested groups let a list mix AND and OR, e.g. "stage is X AND
                 (owner is me OR value > 10k)". --}}
            @foreach ($filters['groups'] ?? [] as $groupIndex => $group)
                <div
                    class="mt-3 rounded-lg border border-border bg-muted/40 p-3"
                    wire:key="filter-group-{{ $groupIndex }}"
                >
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Group &mdash; match</span>

                        <select
                            class="{{ $joinClass }}"
                            wire:model.live="filters.groups.{{ $groupIndex }}.match"
                            aria-label="Match all or any condition in this group"
                        >
                            <option value="{{ FilterGroup::MATCH_ALL }}">all</option>
                            <option value="{{ FilterGroup::MATCH_ANY }}">any</option>
                        </select>

                        <button
                            type="button"
                            class="ml-auto rounded-lg p-1.5 text-muted-foreground hover:bg-card hover:text-destructive"
                            wire:click="removeFilterGroup({{ $groupIndex }})"
                            aria-label="Remove this group"
                        >
                            <x-icon name="lucide-trash-2" />
                        </button>
                    </div>

                    <div class="mt-2 space-y-2">
                        @foreach ($group['conditions'] ?? [] as $index => $condition)
                            <div wire:key="filter-group-{{ $groupIndex }}-condition-{{ $index }}">
                                <x-filter-builder.condition
                                    :condition="$condition"
                                    :index="$index"
                                    :group-index="$groupIndex"
                                    :fields="$fields"
                                />
                            </div>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline"
                        wire:click="addCondition({{ $groupIndex }})"
                    >
                        <x-icon name="lucide-plus" class="h-3.5 w-3.5" />
                        Add condition
                    </button>
                </div>
            @endforeach

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-border pt-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline"
                    wire:click="addCondition"
                >
                    <x-icon name="lucide-plus" />
                    Add condition
                </button>

                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline"
                    wire:click="addFilterGroup"
                >
                    <x-icon name="lucide-group" />
                    Add group
                </button>

                @if ($count > 0)
                    <button
                        type="button"
                        class="ml-auto inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-destructive"
                        wire:click="clearFilters"
                    >
                        <x-icon name="lucide-x" />
                        Clear all
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>
