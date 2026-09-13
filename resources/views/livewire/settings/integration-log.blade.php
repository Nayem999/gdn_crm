<div>
    <x-settings-shell
        heading="Delivery log"
        description="Everything an outside system has sent, and what the CRM did with it."
        active="settings.integration-log"
    >
        <div
            x-data="{ message: '', tone: 'success' }"
            x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
            x-show="message"
            x-cloak
            x-transition
            class="mb-4"
        >
            <template x-if="tone === 'error'">
                <x-alert variant="error"><span x-text="message"></span></x-alert>
            </template>
            <template x-if="tone !== 'error'">
                <x-alert variant="success"><span x-text="message"></span></x-alert>
            </template>
        </div>

        {{-- Health, per source. "Is it broken" is the question somebody opens
             this screen with, and a run of recent failures is the honest
             answer — a source that has delivered ten thousand records and
             failed forty times is fine. --}}
        @php($health = $this->health)
        @if ($health !== [])
            <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($health as $entry)
                    @php($source = $entry['source'])
                    @php($stats = $entry['stats'])
                    <div @class([
                        'rounded-xl border bg-card px-4 py-3',
                        'border-destructive/50' => $stats['failing'] >= $this->alertThreshold(),
                        'border-border' => $stats['failing'] < $this->alertThreshold(),
                    ])>
                        <div class="flex items-start justify-between gap-2">
                            <button
                                type="button"
                                wire:click="$set('sourceId', '{{ $source->id }}')"
                                class="text-left text-sm font-semibold text-foreground hover:text-accent hover:underline"
                            >{{ $source->name }}</button>

                            @if ($stats['failing'] >= $this->alertThreshold())
                                {!! \App\Domain\Shared\UI\ChipPalette::chip('Failing', 'rose') !!}
                            @elseif ($stats['failing'] > 0)
                                {!! \App\Domain\Shared\UI\ChipPalette::chip($stats['failing'] . ' in a row', 'amber') !!}
                            @endif
                        </div>

                        <p class="mt-1 text-xs text-muted-foreground">
                            Last day: {{ number_format($stats['processed']) }} processed,
                            {{ number_format($stats['skipped']) }} skipped,
                            <span @class(['text-destructive' => $stats['failed'] > 0])>{{ number_format($stats['failed']) }} failed</span>
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            @if ($stats['last'])
                                Last delivery {{ $stats['last']->diffForHumans() }}
                            @else
                                Nothing has ever arrived
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
            <div class="w-full max-w-64" wire:key="log-source-{{ $sourceId }}">
                <x-select
                    name="sourceId"
                    label="Source"
                    :options="$this->sourceOptions()"
                    :selected="$sourceId"
                    placeholder="Every source"
                    clearable
                    wire:model.live="sourceId"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2 pb-1">
                @foreach ([
                    'failed' => 'Failed',
                    'today' => 'Today',
                    'unmapped' => 'Made nothing',
                    'sandbox' => 'Sandbox',
                ] as $chip => $label)
                    <button
                        type="button"
                        wire:click="setQuickFilter('{{ $chip }}')"
                        @class([
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            'border-accent bg-accent/10 text-accent' => $quickFilter === $chip,
                            'border-border text-muted-foreground hover:text-foreground' => $quickFilter !== $chip,
                        ])
                        aria-pressed="{{ $quickFilter === $chip ? 'true' : 'false' }}"
                    >{{ $label }}</button>
                @endforeach

                @if ($quickFilter !== '' || $sourceId !== '' || $this->hasActiveFilters())
                    <button type="button" wire:click="clearAllFilters" class="text-xs font-medium text-muted-foreground hover:text-destructive">
                        Clear all
                    </button>
                @endif
            </div>
        </div>

        <x-data-view
            :view="$this"
            :records="$this->rows"
            search-placeholder="Search the payloads…"
            empty-icon="scroll-text"
            empty-heading="Nothing has arrived yet"
            empty-description="Every delivery an outside system makes is recorded here, whether or not it produced anything."
        >
            <x-slot:bulk-actions>
                @if ($this->canReplay())
                    <button
                        type="button"
                        wire:click="replaySelected"
                        wire:confirm="Send the selected deliveries through the pipeline again?"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                    >
                        <x-icon name="lucide-rotate-ccw" class="h-4 w-4" />
                        Send again
                    </button>
                @endif
            </x-slot:bulk-actions>
        </x-data-view>

        {{-- The inspector. What arrived, and what the mapping made of it, side
             by side — which is the pair somebody needs to see to work out why a
             delivery did not do what they expected. --}}
        @php($event = $this->inspecting())
        @if ($event)
            <div
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 sm:p-8"
                wire:key="inspector-{{ $event->id }}"
            >
                <div class="w-full max-w-4xl rounded-xl border border-border bg-card p-5 shadow-xl sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-foreground">
                                {{ $event->received_at->format('j M Y, H:i:s') }}
                            </h2>
                            <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                {{ $event->dataSource?->name }}
                                {!! \App\Domain\Shared\UI\ChipPalette::chip($event->status()->label(), $event->status()->color()) !!}
                                @if ($event->is_sandbox)
                                    {!! \App\Domain\Shared\UI\ChipPalette::chip('Sandbox', 'amber') !!}
                                @endif
                                @if ($event->external_id)
                                    <span class="font-mono">their id {{ $event->external_id }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            @if ($this->canReplay())
                                <button
                                    type="button"
                                    wire:click="replay({{ $event->id }})"
                                    class="text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                >Send again</button>
                            @endif
                            <button type="button" wire:click="stopInspecting" class="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                                Close
                            </button>
                        </div>
                    </div>

                    @if ($event->error)
                        <x-alert variant="error" class="mt-4">{{ $event->error }}</x-alert>
                    @endif

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">What arrived</p>
                            <pre class="max-h-96 overflow-auto rounded-lg bg-background p-3 font-mono text-xs text-foreground">{{ $this->prettyPayload() }}</pre>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ number_format($event->payloadBytes()) }} bytes
                                @if ($event->ip_address)
                                    &middot; from {{ $event->ip_address }}
                                @endif
                                &middot; signature {{ $event->signature_verified ? 'verified' : 'not checked' }}
                            </p>
                        </div>

                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">What the mapping made of it</p>

                            @if (filled($event->mapped_output))
                                <div class="overflow-hidden rounded-lg border border-border">
                                    <table class="min-w-full text-sm">
                                        <tbody class="divide-y divide-border">
                                            @foreach ($event->mapped_output as $field => $value)
                                                <tr>
                                                    <td class="w-1/3 bg-muted/40 px-3 py-1.5 font-mono text-xs text-muted-foreground">{{ $field }}</td>
                                                    <td class="px-3 py-1.5 text-foreground">{{ $value }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="rounded-lg border border-border px-3 py-2 text-xs text-muted-foreground">
                                    Nothing — either the mapping found no values, or the delivery never got that far.
                                </p>
                            @endif

                            @if ($event->record_id)
                                <p class="mt-2 text-xs text-muted-foreground">
                                    {{ $event->outcome }} a record in {{ $event->dataSource?->targetLabel() }}.
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-settings-shell>
</div>
