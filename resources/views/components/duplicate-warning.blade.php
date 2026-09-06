@props(['matches' => [], 'route' => null])

{{-- Shown while a record is being typed. Informative, never blocking: a
     genuine near-duplicate does exist (two people at one company sharing a
     switchboard number), and only the person entering it can tell. --}}
@if ($matches !== [])
    <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
        <p class="flex items-center gap-2 text-sm font-medium text-amber-900 dark:text-amber-200">
            <x-icon name="lucide-triangle-alert" class="h-4 w-4 shrink-0" />
            {{ count($matches) === 1 ? 'This may already exist' : 'These may already exist' }}
        </p>

        <ul class="mt-2 space-y-1.5">
            @foreach ($matches as $match)
                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-amber-800 dark:text-amber-200/80">
                    @if ($route)
                        <a href="{{ $route($match->record) }}" wire:navigate class="font-medium underline hover:no-underline">
                            {{ $match->record->displayName() }}
                        </a>
                    @else
                        <span class="font-medium">{{ $match->record->displayName() }}</span>
                    @endif

                    <span class="text-amber-800/70 dark:text-amber-200/60">&mdash; {{ $match->summary() }}</span>

                    @if ($match->confidence())
                        <x-status-chip :color="$match->confidence()->color()" dot>
                            {{ $match->confidence()->shortLabel() }}
                        </x-status-chip>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
