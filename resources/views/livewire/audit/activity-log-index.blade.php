<div>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-foreground">Audit log</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Every create, update and delete across the CRM, with who did it and what changed. Credentials are never recorded.
        </p>
    </div>

    <div class="rounded-xl border border-border bg-card">
        <div class="flex flex-wrap items-center gap-3 border-b border-border p-4">
            <div class="relative w-full max-w-xs">
                <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <label for="audit-search" class="sr-only">Search the audit log</label>
                <input
                    id="audit-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search description or person..."
                    class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
            </div>

            <div class="w-40">
                <x-select
                    name="event"
                    :options="$this->eventOptions()"
                    :selected="$event"
                    placeholder="Any action"
                    wire:model.live="event"
                />
            </div>

            <div class="w-48">
                <x-select
                    name="subjectType"
                    :options="$this->subjectTypeOptions()"
                    :selected="$subjectType"
                    placeholder="Any record type"
                    wire:model.live="subjectType"
                />
            </div>

            @if ($this->hasFilters())
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-accent hover:underline">
                    Clear all
                </button>
            @endif

            <div wire:loading wire:target="search, event, subjectType, perPage, gotoPage, nextPage, previousPage" class="flex items-center gap-2 text-sm text-muted-foreground">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Loading
            </div>

            <div class="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                <label for="audit-per-page">Per page</label>
                <select
                    id="audit-per-page"
                    wire:model.live="perPage"
                    class="rounded-lg border border-border bg-background px-2 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        @if ($activities->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <x-lucide-scroll-text class="h-6 w-6" aria-hidden="true" />
                </span>
                @if ($this->hasFilters())
                    <p class="text-sm font-medium text-foreground">Nothing matches those filters.</p>
                    <button type="button" wire:click="clearFilters" class="text-sm font-medium text-accent hover:underline">Clear all</button>
                @else
                    <p class="text-sm font-medium text-foreground">Nothing recorded yet.</p>
                    <p class="text-sm text-muted-foreground">Changes to company details, users, teams and roles will appear here.</p>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">When</th>
                            <th scope="col" class="px-4 py-3 font-medium">Who</th>
                            <th scope="col" class="px-4 py-3 font-medium">Action</th>
                            <th scope="col" class="px-4 py-3 font-medium">Record</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Changes</th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="opacity-50">
                        @foreach ($activities as $activity)
                            @php($changes = $this->changesFor($activity))
                            <tr class="border-b border-border" wire:key="activity-{{ $activity->id }}">
                                <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">
                                    <span title="{{ $activity->created_at?->toDayDateTimeString() }}">
                                        {{ $activity->created_at?->diffForHumans() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($activity->causer)
                                        <div class="flex items-center gap-2">
                                            <x-avatar :user="$activity->causer" size="sm" />
                                            <div class="min-w-0">
                                                <p class="truncate font-medium text-foreground">{{ $activity->causer->name }}</p>
                                                <p class="truncate text-xs text-muted-foreground">{{ $activity->causer->email }}</p>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-xs text-muted-foreground">System</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-chip :color="$this->eventColor($activity->event)">
                                        {{ str($activity->event ?? 'activity')->headline() }}
                                    </x-status-chip>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-foreground">{{ $activity->description }}</p>
                                    @if ($activity->subject_type)
                                        <p class="text-xs text-muted-foreground">
                                            {{ str(class_basename($activity->subject_type))->headline() }}
                                            @if ($activity->subject_id)
                                                #{{ $activity->subject_id }}
                                            @endif
                                        </p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($changes !== [])
                                        <button
                                            type="button"
                                            wire:click="toggle({{ $activity->id }})"
                                            class="inline-flex items-center gap-1 text-sm font-medium text-accent hover:underline"
                                            aria-expanded="{{ $this->isExpanded($activity->id) ? 'true' : 'false' }}"
                                        >
                                            {{ $this->isExpanded($activity->id) ? 'Hide' : 'Show' }}
                                            {{ count($changes) }} {{ str('field')->plural(count($changes)) }}
                                        </button>
                                    @else
                                        <span class="text-xs text-muted-foreground">—</span>
                                    @endif
                                </td>
                            </tr>

                            @if ($this->isExpanded($activity->id) && $changes !== [])
                                <tr class="border-b border-border bg-background/50" wire:key="activity-detail-{{ $activity->id }}">
                                    <td colspan="5" class="px-4 py-4">
                                        <table class="w-full text-left text-xs">
                                            <thead class="text-muted-foreground">
                                                <tr>
                                                    <th scope="col" class="pb-2 pr-4 font-medium">Field</th>
                                                    <th scope="col" class="pb-2 pr-4 font-medium">Before</th>
                                                    <th scope="col" class="pb-2 font-medium">After</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($changes as $field => $change)
                                                    <tr>
                                                        <td class="py-1 pr-4 font-mono text-foreground">{{ $field }}</td>
                                                        <td class="py-1 pr-4 text-muted-foreground">{{ $this->formatValue($change['old']) }}</td>
                                                        <td class="py-1 text-foreground">{{ $this->formatValue($change['new']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($activities->hasPages())
                <div class="border-t border-border p-4">
                    {{ $activities->onEachSide(1)->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
