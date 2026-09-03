<div>
    <x-settings-shell
        heading="Notification matrix"
        description="Who hears about what, and how. Switch a channel off for a recipient type and it stops going out."
        active="settings.notifications"
    >
        <div
            x-data="{ show: false }"
            x-on:matrix-changed.window="show = true; setTimeout(() => show = false, 2000)"
            x-show="show"
            x-cloak
            x-transition
            class="mb-4"
        >
            <x-alert variant="success">Saved.</x-alert>
        </div>

        @php
            $unavailable = collect($channels)->reject(fn ($channel) => $this->isAvailable($channel));
        @endphp

        @if ($unavailable->isNotEmpty())
            <div class="mb-6">
                <x-alert variant="info">
                    {{ $unavailable->map(fn ($channel) => $channel->label())->join(', ', ' and ') }}
                    {{ $unavailable->count() === 1 ? 'is' : 'are' }} not connected yet, so anything switched on for
                    {{ $unavailable->count() === 1 ? 'it' : 'them' }} is recorded in the log as skipped rather than sent.
                </x-alert>
            </div>
        @endif

        <div class="space-y-6">
            @foreach ($grouped as $groupName => $events)
                <section class="overflow-hidden rounded-xl border border-border bg-card">
                    <header class="border-b border-border px-4 py-3">
                        <h2 class="text-sm font-semibold text-foreground">{{ $groupName }}</h2>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-muted/40">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        Event
                                    </th>
                                    <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        Recipient
                                    </th>
                                    @foreach ($channels as $channel)
                                        <th scope="col" class="px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-icon :name="'lucide-' . $channel->icon()" class="h-3.5 w-3.5" />
                                                {{ $channel->label() }}
                                            </span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-border">
                                @foreach ($events as $event)
                                    @foreach ($event->recipientTypes as $index => $type)
                                        <tr class="hover:bg-muted/30" wire:key="cell-{{ $event->key }}-{{ $type->value }}">
                                            @if ($index === 0)
                                                <td class="px-4 py-3 align-top" rowspan="{{ count($event->recipientTypes) }}">
                                                    <p class="font-medium text-foreground">{{ $event->label }}</p>
                                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ $event->description }}</p>
                                                </td>
                                            @endif

                                            <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">
                                                {{ $type->label() }}
                                            </td>

                                            @foreach ($channels as $channel)
                                                <td class="px-3 py-3 text-center">
                                                    <label class="sr-only" for="cell-{{ $event->key }}-{{ $type->value }}-{{ $channel->value }}">
                                                        {{ $event->label }} to {{ $type->label() }} by {{ $channel->label() }}
                                                    </label>
                                                    <input
                                                        id="cell-{{ $event->key }}-{{ $type->value }}-{{ $channel->value }}"
                                                        type="checkbox"
                                                        class="rounded border-border text-accent focus:ring-accent/40 disabled:opacity-40"
                                                        @checked($this->isEnabled($event->key, $type, $channel))
                                                        @disabled(! $this->canUpdate())
                                                        wire:click="toggle('{{ $event->key }}', '{{ $type->value }}', '{{ $channel->value }}'); $dispatch('matrix-changed')"
                                                        @if (! $this->isAvailable($channel))
                                                            title="{{ $this->unavailableReason($channel) }}"
                                                        @endif
                                                    />
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>

        @unless ($this->canUpdate())
            <p class="mt-4 text-sm text-muted-foreground">You have read-only access to the matrix.</p>
        @endunless

        <x-slot:actions>
            @can('update', App\Domain\Notifications\Models\NotificationLog::class)
                <a href="{{ route('settings.notifications.templates') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    <x-icon name="lucide-file-text" />
                    Templates
                </a>
            @endcan

            <a href="{{ route('settings.notifications.log') }}" wire:navigate
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                <x-icon name="lucide-scroll-text" />
                Delivery log
            </a>
        </x-slot:actions>
    </x-settings-shell>
</div>
