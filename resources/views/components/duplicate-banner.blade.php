@props(['matches' => [], 'mergeUrl' => null, 'canMerge' => false, 'mergedInto' => null, 'mergedLabel' => null])

{{-- Where a merged-away record went. Shown instead of a duplicate warning:
     this one is already resolved. --}}
@if ($mergedInto !== null)
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-muted/50 px-4 py-3">
        <p class="flex items-center gap-2 text-sm text-muted-foreground">
            <x-icon name="lucide-merge" class="h-4 w-4 shrink-0" />
            This record was merged into
            <a href="{{ $mergedInto }}" wire:navigate class="font-medium text-accent hover:underline">
                {{ $mergedLabel }}
            </a>
            and is kept for its history.
        </p>
    </div>
@elseif ($matches !== [])
    @php
        $strongest = $matches[0]->confidence();
        $count = count($matches);
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
        <div>
            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-amber-900 dark:text-amber-200">
                <x-icon name="lucide-copy" class="h-4 w-4 shrink-0" />
                {{ $count }} possible {{ Str::plural('duplicate', $count) }}

                @if ($strongest)
                    <x-status-chip :color="$strongest->color()" dot>{{ $strongest->shortLabel() }}</x-status-chip>
                @endif
            </p>

            <p class="mt-0.5 text-sm text-amber-800/80 dark:text-amber-200/70">
                {{ $matches[0]->summary() }}{{ $count > 1 ? ', and '.($count - 1).' more' : '' }}.
            </p>
        </div>

        @if ($canMerge && $mergeUrl !== null)
            <a href="{{ $mergeUrl }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg border border-amber-400 bg-white/70 px-3 py-1.5 text-sm font-semibold text-amber-900 hover:bg-white dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100 dark:hover:bg-amber-500/20">
                <x-icon name="lucide-merge" />
                Review and merge
            </a>
        @endif
    </div>
@endif
