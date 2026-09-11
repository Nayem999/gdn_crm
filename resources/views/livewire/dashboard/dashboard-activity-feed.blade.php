@php($entries = $this->entries)

<div class="rounded-xl border border-border bg-card p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-base font-semibold text-foreground">Recent activity</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Changes to records you can see.</p>
        </div>

        @can('viewAny', Spatie\Activitylog\Models\Activity::class)
            <a href="{{ route('settings.audit') }}" wire:navigate
               class="text-xs font-medium text-accent hover:underline">Full audit log</a>
        @endcan
    </div>

    @if (! $this->seesAnything())
        <x-empty-state
            icon="lock"
            heading="Nothing to show"
            description="This feed follows the records you have access to. You do not have any modules yet."
        />
    @elseif ($entries === [])
        <x-empty-state
            icon="history"
            heading="Nothing has happened yet"
            description="Create or change a record and it shows up here."
        />
    @else
        <ol class="space-y-0" wire:loading.class="opacity-60" wire:target="loadMore">
            @foreach ($entries as $index => $entry)
                {{-- The same component the record timeline uses, so one audit
                     entry reads identically wherever it appears. --}}
                <x-timeline-entry
                    :entry="$entry"
                    :last="$index === count($entries) - 1"
                    wire:key="feed-{{ $entry->kind->value }}-{{ $entry->id }}"
                />
            @endforeach
        </ol>

        @if ($this->hasMore())
            <div class="mt-2 text-center">
                <button
                    type="button"
                    wire:click="loadMore"
                    wire:loading.attr="disabled"
                    wire:target="loadMore"
                    class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground disabled:opacity-60"
                >
                    Show more
                </button>
            </div>
        @endif
    @endif
</div>
