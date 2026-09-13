<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('reports.index') }}" wire:navigate class="hover:text-foreground">Reports</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">Forecast</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">Sales forecast</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                Two forecasts, side by side. The weighted one knows about the deals you have; the historical one knows
                what usually happens. The gap between them is the part worth reading.
            </p>
        </div>

        <div class="w-52" wire:key="forecast-months">
            <x-select
                name="months"
                :options="$this->monthOptions()"
                :selected="$months"
                placeholder="How far ahead…"
                wire:model.live="months"
            />
        </div>
    </div>

    @php($totals = $this->totals())

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Already won', 'value' => $totals['committed'], 'hint' => 'A fact'],
            ['label' => 'Weighted pipeline', 'value' => $totals['weighted'], 'hint' => 'A judgement'],
            ['label' => 'Expected', 'value' => $totals['expected'], 'hint' => 'Won plus weighted'],
            ['label' => 'Best case', 'value' => $totals['best_case'], 'hint' => 'Everything lands'],
        ] as $card)
            <div class="rounded-xl border border-border bg-card px-4 py-3">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ $card['label'] }}</p>
                <p class="mt-0.5 text-xl font-semibold tabular-nums text-foreground">
                    {{ number_format($card['value'], 2) }}
                </p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">{{ $card['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <section class="mb-6 rounded-xl border border-border bg-card p-5">
        <h2 class="text-base font-semibold text-foreground">Month by month</h2>
        <p class="mt-1 text-sm text-muted-foreground">
            A typical month lately was {{ number_format($this->historicalAverage(), 2) }}.
        </p>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead>
                    <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th class="py-2 pr-3 font-medium">Month</th>
                        <th class="py-2 pr-3 text-right font-medium">Won</th>
                        <th class="py-2 pr-3 text-right font-medium">Weighted</th>
                        <th class="py-2 pr-3 text-right font-medium">Expected</th>
                        <th class="py-2 pr-3 text-right font-medium">Best case</th>
                        <th class="py-2 text-right font-medium">Against a typical month</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->forecasts as $forecast)
                        <tr class="border-b border-border/60 last:border-0">
                            <td class="py-2 pr-3 font-medium text-foreground">
                                {{ $forecast->period->from->format('M Y') }}
                                <span class="ml-1 text-xs text-muted-foreground">
                                    {{ $forecast->openCount }} open
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums text-foreground">
                                {{ number_format($forecast->committed, 2) }}
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums text-muted-foreground">
                                {{ number_format($forecast->weighted, 2) }}
                            </td>
                            <td class="py-2 pr-3 text-right font-semibold tabular-nums text-foreground">
                                {{ number_format($forecast->expected(), 2) }}
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums text-muted-foreground">
                                {{ number_format($forecast->bestCase, 2) }}
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                @if ($forecast->versusHistory() === null)
                                    <span class="text-muted-foreground">&mdash;</span>
                                @else
                                    {{-- Said out loud when the pipeline is promising
                                         well above what usually lands: that gap is
                                         the whole point of showing both. --}}
                                    <span @class([
                                        'text-amber-600 dark:text-amber-400' => $forecast->isOptimistic(),
                                        'text-muted-foreground' => ! $forecast->isOptimistic(),
                                    ])>
                                        {{ $forecast->versusHistory() > 0 ? '+' : '' }}{{ $forecast->versusHistory() }}%
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (collect($this->forecasts)->contains(fn ($forecast) => $forecast->isOptimistic()))
            <p class="mt-3 text-xs text-amber-600 dark:text-amber-400">
                One or more months are forecasting well above a typical month. That is either a very good quarter or a
                pipeline that is not going to land.
            </p>
        @endif
    </section>

    <section class="rounded-xl border border-border bg-card p-5">
        <h2 class="text-base font-semibold text-foreground">What actually closed</h2>
        <p class="mt-1 text-sm text-muted-foreground">
            The last {{ \App\Domain\Reports\Forecasting\ForecastCalculator::HISTORY_MONTHS }} whole months. The current
            month is left out — it is half over, and averaging it in would drag every figure down.
        </p>

        @php($history = $this->history)
        @php($peak = max(1, ...array_values($history) ?: [1]))

        <div class="mt-4 space-y-2">
            @foreach ($history as $month => $value)
                <div class="flex items-center gap-3">
                    <span class="w-20 shrink-0 text-xs text-muted-foreground">{{ $month }}</span>
                    <div class="h-5 flex-1 overflow-hidden rounded bg-muted">
                        <div class="h-full rounded bg-accent/70"
                             style="width: {{ max(0.5, round($value / $peak * 100, 2)) }}%"
                             role="img"
                             aria-label="{{ $month }}: {{ number_format($value, 2) }}"></div>
                    </div>
                    <span class="w-28 shrink-0 text-right text-xs tabular-nums text-foreground">
                        {{ number_format($value, 2) }}
                    </span>
                </div>
            @endforeach
        </div>
    </section>
</div>
