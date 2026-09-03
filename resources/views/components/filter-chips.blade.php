@props(['chips' => [], 'search' => ''])

@if ($chips !== [] || $search !== '')
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
        @if ($search !== '')
            <span class="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-foreground">
                <x-icon name="lucide-search" class="h-3.5 w-3.5" />
                &ldquo;{{ $search }}&rdquo;
                <button type="button" wire:click="$set('search', '')" class="text-muted-foreground hover:text-destructive" aria-label="Clear search">
                    <x-icon name="lucide-x" class="h-3 w-3" />
                </button>
            </span>
        @endif

        @foreach ($chips as $chip)
            <span
                class="inline-flex items-center gap-1.5 rounded-full bg-accent/10 px-2.5 py-1 text-xs font-medium text-accent"
                wire:key="chip-{{ $chip['group'] ?? 'root' }}-{{ $chip['index'] }}"
            >
                {{ $chip['label'] }}
                <button
                    type="button"
                    class="hover:text-destructive"
                    wire:click="removeCondition({{ $chip['index'] }}{{ $chip['group'] === null ? '' : ', '.$chip['group'] }})"
                    aria-label="Remove filter {{ $chip['label'] }}"
                >
                    <x-icon name="lucide-x" class="h-3 w-3" />
                </button>
            </span>
        @endforeach

        @if (count($chips) > 1)
            <button type="button" class="text-xs font-medium text-muted-foreground hover:text-destructive" wire:click="clearFilters">
                Clear all
            </button>
        @endif
    </div>
@endif
