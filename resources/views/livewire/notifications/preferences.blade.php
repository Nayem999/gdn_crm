<div id="notifications">
    <div
        x-data="{ show: false }"
        x-on:preferences-saved.window="show = true; setTimeout(() => show = false, 2000)"
        x-show="show"
        x-cloak
        x-transition
        class="mb-4"
    >
        <x-alert variant="success">Preferences saved.</x-alert>
    </div>

    <section>
        <h2 class="text-base font-semibold text-foreground">Notifications</h2>
        <p class="mt-1 text-sm text-muted-foreground">
            Turn a channel off to stop hearing from it. An administrator decides what is sent in the first
            place; these settings only ever quieten things down.
        </p>

        <div class="mt-4 space-y-2">
            @foreach ($channels as $channel)
                <label class="flex cursor-pointer items-center justify-between gap-4 rounded-lg border border-border bg-card px-4 py-3">
                    <span class="flex items-center gap-2.5 text-sm font-medium text-foreground">
                        <x-icon :name="'lucide-' . $channel->icon()" class="h-4 w-4 text-muted-foreground" />
                        {{ $channel->label() }}
                    </span>

                    <input
                        type="checkbox"
                        class="rounded border-border text-accent focus:ring-accent/40"
                        @checked(! $this->channelIsMuted($channel->value))
                        wire:click="toggleChannel('{{ $channel->value }}')"
                        aria-label="Receive {{ $channel->label() }} notifications"
                    />
                </label>
            @endforeach
        </div>
    </section>

    @if ($rows !== [])
        <section class="mt-8">
            <h3 class="text-sm font-semibold text-foreground">Individual notifications</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                Only what is currently switched on for you is listed.
            </p>

            <div class="mt-4 overflow-hidden rounded-xl border border-border bg-card">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Notification</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Channels</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border">
                        @foreach ($rows as $row)
                            <tr wire:key="pref-{{ $row['event']->key }}">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-foreground">{{ $row['event']->label }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ $row['event']->description }}</p>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-3">
                                        @foreach ($row['channels'] as $channel)
                                            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground">
                                                <input
                                                    type="checkbox"
                                                    class="rounded border-border text-accent focus:ring-accent/40"
                                                    @checked(! $this->eventIsMuted($row['event']->key, $channel->value))
                                                    wire:click="toggleEvent('{{ $row['event']->key }}', '{{ $channel->value }}')"
                                                    aria-label="{{ $row['event']->label }} by {{ $channel->label() }}"
                                                />
                                                {{ $channel->label() }}
                                            </label>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
