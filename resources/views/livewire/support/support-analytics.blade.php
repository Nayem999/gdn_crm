<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('tickets.index') }}" wire:navigate class="hover:text-foreground">Support</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">Analytics</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">Support analytics</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ $this->period()->label }} &mdash;
                {{ $this->period()->from->format('j M') }} to {{ $this->period()->to->format('j M Y') }}.
                Resolution time is net of time on hold; a first reply is measured from arrival.
            </p>
        </div>

        <div class="w-56" wire:key="analytics-period">
            <x-select
                name="periodKey"
                :options="$this->periodOptions()"
                :selected="$periodKey"
                placeholder="Choose a window…"
                wire:model.live="periodKey"
            />
        </div>
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['label' => 'Raised', 'value' => number_format($this->volume['raised']), 'tone' => 'foreground'],
            ['label' => 'Resolved', 'value' => number_format($this->volume['resolved']), 'tone' => 'foreground'],
            ['label' => 'Open now', 'value' => number_format($this->volume['open_now']), 'tone' => 'foreground'],
            ['label' => 'Missed a promise', 'value' => number_format($this->volume['breached']), 'tone' => $this->volume['breached'] > 0 ? 'destructive' : 'foreground'],
            ['label' => 'Within SLA', 'value' => $this->resolution['within_sla'] === null ? '—' : $this->resolution['within_sla'] . '%', 'tone' => 'foreground'],
        ] as $card)
            <div class="rounded-xl border border-border bg-card px-4 py-3">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ $card['label'] }}</p>
                <p @class([
                    'mt-0.5 text-xl font-semibold tabular-nums',
                    'text-destructive' => $card['tone'] === 'destructive',
                    'text-foreground' => $card['tone'] !== 'destructive',
                ])>{{ $card['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-base font-semibold text-foreground">Time to resolve</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Over {{ number_format($this->resolution['count']) }}
                {{ \Illuminate\Support\Str::plural('ticket', $this->resolution['count']) }} resolved in the window.
            </p>
            <dl class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">Median</dt>
                    <dd class="mt-0.5 text-2xl font-semibold tabular-nums text-foreground">
                        {{ $this->hours($this->resolution['median']) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">Average</dt>
                    <dd class="mt-0.5 text-2xl font-semibold tabular-nums text-muted-foreground">
                        {{ $this->hours($this->resolution['average']) }}
                    </dd>
                </div>
            </dl>
            {{-- The median leads and the average follows, because one ticket
                 that sat over a bank holiday drags a mean by days. --}}
            <p class="mt-3 text-xs text-muted-foreground">
                The median is the typical ticket. A much larger average means a few ran very long.
            </p>
        </section>

        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-base font-semibold text-foreground">Time to first reply</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Over {{ number_format($this->firstResponse['count']) }} answered
                {{ \Illuminate\Support\Str::plural('ticket', $this->firstResponse['count']) }}.
            </p>
            <dl class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">Median</dt>
                    <dd class="mt-0.5 text-2xl font-semibold tabular-nums text-foreground">
                        {{ $this->minutes($this->firstResponse['median']) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">Average</dt>
                    <dd class="mt-0.5 text-2xl font-semibold tabular-nums text-muted-foreground">
                        {{ $this->minutes($this->firstResponse['average']) }}
                    </dd>
                </div>
            </dl>
            @if ($this->firstResponse['unanswered'] > 0)
                {{-- Beside the average, never folded into it: ten answered in a
                     minute and forty ignored is a wonderful average. --}}
                <p class="mt-3 text-xs text-destructive">
                    {{ number_format($this->firstResponse['unanswered']) }} raised in this window are still
                    waiting for a first reply.
                </p>
            @endif
        </section>
    </div>

    <section class="mb-6 rounded-xl border border-border bg-card p-5">
        <h2 class="text-base font-semibold text-foreground">Tickets raised</h2>
        <div class="mt-4 overflow-x-auto">
            <div class="flex min-w-full items-end gap-1" style="height: 8rem" role="img"
                 aria-label="Tickets raised per day across the window">
                @foreach ($this->byDay as $day => $count)
                    <div class="flex min-w-[0.5rem] flex-1 flex-col items-center justify-end gap-1"
                         title="{{ \Illuminate\Support\Carbon::parse($day)->format('j M') }}: {{ $count }}">
                        <span class="text-[10px] tabular-nums text-muted-foreground">{{ $count > 0 ? $count : '' }}</span>
                        <div class="w-full rounded-t bg-accent/70"
                             style="height: {{ max(2, (int) round($count / $this->busiestDay($this->byDay) * 100)) }}%"></div>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="mt-2 text-xs text-muted-foreground">
            Every day in the window is drawn, including the quiet ones.
        </p>
    </section>

    <section class="mb-6 rounded-xl border border-border bg-card p-5">
        <h2 class="text-base font-semibold text-foreground">By agent</h2>

        @if ($this->agents === [])
            <p class="mt-3 text-sm text-muted-foreground">Nothing to report for this window.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Agent</th>
                            <th class="py-2 pr-3 text-right font-medium">Resolved</th>
                            <th class="py-2 pr-3 text-right font-medium">Still open</th>
                            <th class="py-2 pr-3 text-right font-medium">Typical resolve</th>
                            <th class="py-2 text-right font-medium">Missed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->agents as $agent)
                            <tr class="border-b border-border/60">
                                <td class="py-2 pr-3 font-medium text-foreground">{{ $agent->name }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-foreground">{{ number_format($agent->resolved) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-muted-foreground">{{ number_format($agent->stillOpen) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums text-muted-foreground">
                                    {{ $this->hours($agent->averageResolutionHours) }}
                                </td>
                                <td @class([
                                    'py-2 text-right tabular-nums',
                                    'text-destructive' => $agent->breached > 0,
                                    'text-muted-foreground' => $agent->breached === 0,
                                ])>
                                    {{ number_format($agent->breached) }}
                                    @if ($agent->breachRate() !== null && $agent->breached > 0)
                                        <span class="text-xs">({{ $agent->breachRate() }}%)</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                How many somebody got through and how many were late are kept separate on purpose &mdash;
                one score would hide which of them changed.
            </p>
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        @foreach (['priority' => 'By priority', 'source' => 'Came in by', 'status' => 'Where they are'] as $key => $heading)
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">{{ $heading }}</h2>
                <dl class="mt-3 space-y-1.5 text-sm">
                    @foreach ($this->breakdown[$key] as $label => $count)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">{{ $label }}</dt>
                            <dd class="tabular-nums text-foreground">{{ number_format($count) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
</div>
