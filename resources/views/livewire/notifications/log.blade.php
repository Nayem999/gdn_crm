<div>
    <x-settings-shell
        heading="Notification log"
        description="Every delivery the engine attempted, and what became of it. Message bodies are not kept."
        active="settings.notifications"
    >
        <div
            x-data="{ show: false }"
            x-on:notification-retried.window="show = true; setTimeout(() => show = false, 3000)"
            x-show="show"
            x-cloak
            x-transition
            class="mb-4"
        >
            <x-alert variant="success">Put back on the queue.</x-alert>
        </div>

        <div class="rounded-xl border border-border bg-card">
            <div class="flex flex-wrap items-center gap-3 border-b border-border p-4">
                <div class="relative w-full max-w-xs">
                    <x-icon name="lucide-search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <label for="log-search" class="sr-only">Search the notification log</label>
                    <input
                        id="log-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search recipient or subject..."
                        class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    >
                </div>

                {{-- Keyed on the chosen value: Tom Select sits behind
                     wire:ignore, so clearFilters() could not move the selection
                     back to "any" without the node being replaced. --}}
                <div class="w-full sm:w-40" wire:key="log-status-{{ $status }}">
                    <x-select
                        name="status"
                        :options="$statuses"
                        :selected="$status"
                        placeholder="Any status"
                        aria-label="Filter by status"
                        clearable
                        wire:model.live="status"
                    />
                </div>

                <div class="w-full sm:w-40" wire:key="log-channel-{{ $channel }}">
                    <x-select
                        name="channel"
                        :options="$channels"
                        :selected="$channel"
                        placeholder="Any channel"
                        aria-label="Filter by channel"
                        clearable
                        wire:model.live="channel"
                    />
                </div>

                <div class="w-full sm:w-56" wire:key="log-event-{{ $event }}">
                    <x-select
                        name="event"
                        :options="$this->eventOptions()"
                        :selected="$event"
                        placeholder="Any event"
                        aria-label="Filter by event"
                        clearable
                        wire:model.live="event"
                    />
                </div>

                @if ($this->hasFilters())
                    <button type="button" wire:click="clearFilters" class="text-sm font-medium text-accent hover:underline">
                        Clear
                    </button>
                @endif

                <div wire:loading wire:target="search, status, channel, event" class="flex items-center gap-2 text-sm text-muted-foreground">
                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                    Loading
                </div>
            </div>

            @if ($logs->isEmpty())
                <x-empty-state
                    class="m-4"
                    icon="bell-off"
                    heading="Nothing has been sent yet"
                    description="Deliveries appear here as the engine attempts them."
                    :filtered="$this->hasFilters()"
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">When</th>
                                <th scope="col" class="px-4 py-3 font-medium">Event</th>
                                <th scope="col" class="px-4 py-3 font-medium">Recipient</th>
                                <th scope="col" class="px-4 py-3 font-medium">Channel</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>

                        <tbody wire:loading.class="opacity-50">
                            @foreach ($logs as $log)
                                <tr class="border-b border-border last:border-0" wire:key="log-{{ $log->id }}">
                                    <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">
                                        {{ $log->created_at->diffForHumans() }}
                                    </td>

                                    <td class="px-4 py-3">
                                        <p class="font-medium text-foreground">{{ $log->eventLabel() }}</p>
                                        @if ($log->subject)
                                            <p class="truncate text-xs text-muted-foreground">{{ $log->subject }}</p>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3">
                                        <p class="text-foreground">{{ $log->user?->name ?? $log->recipient ?? '—' }}</p>
                                        <p class="text-xs text-muted-foreground">{{ $log->recipientType()->label() }}</p>
                                    </td>

                                    <td class="px-4 py-3">
                                        {{-- x-icon-chip adds the lucide- prefix itself. --}}
                                        <x-icon-chip :icon="$log->channel()->icon()" :label="$log->channel()->label()" />
                                    </td>

                                    <td class="px-4 py-3">
                                        <x-status-chip :color="$log->status()->color()" dot>{{ $log->status()->label() }}</x-status-chip>

                                        @if ($log->error)
                                            <p class="mt-1 max-w-xs text-xs text-muted-foreground">{{ $log->error }}</p>
                                        @endif

                                        @if ($log->attempts > 1)
                                            <p class="mt-0.5 text-xs text-muted-foreground">{{ $log->attempts }} attempts</p>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        @can('retry', $log)
                                            <button
                                                type="button"
                                                wire:click="retry({{ $log->id }})"
                                                wire:loading.attr="disabled"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                                            >
                                                <x-icon name="lucide-rotate-cw" class="h-3.5 w-3.5" />
                                                Retry
                                            </button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($logs->hasPages())
                    <div class="border-t border-border p-4">
                        {{ $logs->onEachSide(1)->links() }}
                    </div>
                @endif
            @endif
        </div>

        <x-slot:actions>
            @if ($this->failedCount() > 0)
                @can('update', App\Domain\Notifications\Models\NotificationLog::class)
                    <x-button type="button" variant="secondary" wire:click="retryAllFailed">
                        <x-icon name="lucide-rotate-cw" />
                        Retry {{ $this->failedCount() }} failed
                    </x-button>
                @endcan
            @endif

            <a href="{{ route('settings.notifications') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                <x-icon name="lucide-table-2" />
                Matrix
            </a>
        </x-slot:actions>
    </x-settings-shell>
</div>
