@props(['count' => 0, 'total' => null, 'showSelectAll' => false])

{{-- Only present while something is selected; it floats above the list so the
     rows underneath stay readable. --}}
@if ($count > 0)
    <div
        {{ $attributes->merge(['class' => 'sticky bottom-4 z-20 mx-auto flex w-fit max-w-full flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-2.5 shadow-lg']) }}
        role="region"
        aria-label="Bulk actions"
    >
        <span class="text-sm font-medium text-foreground">
            {{ number_format($count) }} {{ \Illuminate\Support\Str::plural('record', $count) }} selected
        </span>

        @if ($showSelectAll && $total !== null && $total > $count)
            <button
                type="button"
                class="text-sm font-medium text-accent hover:underline"
                wire:click="selectAllMatchingFilters"
            >
                Select all {{ number_format($total) }}
            </button>
        @endif

        <span class="h-5 w-px bg-border" aria-hidden="true"></span>

        <div class="flex flex-wrap items-center gap-2">
            {{ $slot }}
        </div>

        <button
            type="button"
            class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
            wire:click="clearSelection"
            aria-label="Clear selection"
        >
            <x-icon name="lucide-x" />
        </button>
    </div>
@endif
