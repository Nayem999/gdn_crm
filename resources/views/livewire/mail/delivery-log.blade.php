<div>
    <x-settings-shell
        heading="Email delivery"
        description="Every message handed to a provider, and what the provider reported afterwards. Message bodies are not kept."
        active="settings.mail-log"
    >
        @php($webhook = $this->webhookUrl())

        @if ($webhook !== null)
            <div class="mb-6 rounded-xl border border-border bg-card p-4">
                <h2 class="text-sm font-semibold text-foreground">Webhook address for {{ $this->activeProviderLabel() }}</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Paste this into the provider so bounces, complaints, opens and clicks come back here.
                    Treat it as a credential — anyone who has it can post events.
                </p>
                <code class="mt-2 block overflow-x-auto rounded-lg bg-muted px-3 py-2 text-xs text-foreground">{{ $webhook }}</code>
            </div>
        @else
            <div class="mb-6">
                <x-alert variant="info">
                    {{ $this->activeProviderLabel() }} does not report what happens to a message after it is handed over,
                    so these rows will stay at &ldquo;sent&rdquo;.
                </x-alert>
            </div>
        @endif

        <div class="rounded-xl border border-border bg-card">
            <div class="flex flex-wrap items-center gap-3 border-b border-border p-4">
                <div class="relative w-full max-w-xs">
                    <x-icon name="lucide-search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <label for="mail-log-search" class="sr-only">Search the delivery log</label>
                    <input
                        id="mail-log-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search recipient or subject..."
                        class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    >
                </div>

                <x-select name="mail-log-status" :options="$statuses" :selected="$status" placeholder="Any status" wire:model.live="status" class="w-44" />
                <x-select name="mail-log-provider" :options="$providers" :selected="$provider" placeholder="Any provider" wire:model.live="provider" class="w-44" />

                @if ($this->hasFilters())
                    <button type="button" wire:click="clearFilters" class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Clear
                    </button>
                @endif
            </div>

            @if ($messages->isEmpty())
                <x-empty-state
                    icon="mail"
                    heading="Nothing sent yet"
                    description="Messages appear here as soon as the application sends one."
                />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Recipient</th>
                                <th scope="col" class="px-4 py-3 font-medium">Subject</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">Opens</th>
                                <th scope="col" class="px-4 py-3 font-medium">Clicks</th>
                                <th scope="col" class="px-4 py-3 font-medium">Sent</th>
                                <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">History</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($messages as $message)
                                <tr>
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-foreground">{{ $message->to_email }}</span>
                                        @if ($message->reason)
                                            <span class="mt-0.5 block text-xs text-destructive">{{ $message->reason }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ $message->subject ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <x-status-chip :status="$message->status">{{ $message->status->label() }}</x-status-chip>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ $message->open_count }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ $message->click_count }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ $message->sent_at?->diffForHumans() }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            wire:click="toggle({{ $message->id }})"
                                            class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                        >
                                            {{ $expanded === $message->id ? 'Hide' : 'History' }}
                                        </button>
                                    </td>
                                </tr>

                                @if ($expanded === $message->id)
                                    <tr class="bg-muted/40">
                                        <td colspan="7" class="px-4 py-3">
                                            @if ($message->events->isEmpty())
                                                <p class="text-sm text-muted-foreground">
                                                    Nothing reported back yet.
                                                </p>
                                            @else
                                                <ul class="space-y-1 text-sm">
                                                    @foreach ($message->events as $event)
                                                        <li class="flex flex-wrap items-baseline gap-2">
                                                            <span class="font-medium text-foreground">{{ $event->type->label() }}</span>
                                                            <span class="text-xs text-muted-foreground">{{ $event->occurred_at?->diffForHumans() }}</span>
                                                            @if ($event->url)
                                                                <span class="text-xs text-muted-foreground">{{ $event->url }}</span>
                                                            @endif
                                                            @if ($event->reason)
                                                                <span class="text-xs text-destructive">{{ $event->reason }}</span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-border p-4">
                    {{ $messages->links() }}
                </div>
            @endif
        </div>
    </x-settings-shell>
</div>
