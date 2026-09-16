<div>
    <x-settings-shell
        heading="Meta performance"
        description="What the advertising cost, and what the CRM got for it."
        active="settings.meta.performance"
    >
        {{-- The window, and the honesty about what it measures. --}}
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-form.label for="from">From</x-form.label>
                        <x-form.input id="from" type="date" wire:model.live="from" class="w-44" />
                    </div>

                    <div>
                        <x-form.label for="to">To</x-form.label>
                        <x-form.input id="to" type="date" wire:model.live="to" class="w-44" />
                    </div>
                </div>

                @if ($this->readAt() !== null)
                    {{-- Said rather than implied: Meta restates a day's spend for
                         up to 72 hours, so a figure is only meaningful beside the
                         moment it was read. --}}
                    <p class="text-xs text-muted-foreground">
                        Read from Meta {{ $this->readAt()->diffForHumans() }}.
                    </p>
                @endif
            </div>
        </section>

        {{-- The totals. --}}
        <section class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($this->cards() as $card)
                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $card['label'] }}</p>
                    <p class="mt-1 text-xl font-semibold text-foreground">{{ $card['value'] }}</p>
                    @if ($card['hint'])
                        <p class="mt-1 text-xs text-muted-foreground">{{ $card['hint'] }}</p>
                    @endif
                </div>
            @endforeach
        </section>

        {{-- The chain itself. --}}
        <section class="mt-6 rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($campaignId !== '')
                        <x-button type="button" variant="secondary" wire:click="back">
                            <x-icon name="lucide-arrow-left" />
                            Back
                        </x-button>
                    @endif

                    <h2 class="text-sm font-semibold text-foreground">
                        {{ $this->level() }}
                        @if ($this->crumb())
                            <span class="font-normal text-muted-foreground">in {{ $this->crumb() }}</span>
                        @endif
                    </h2>
                </div>

                <p class="text-xs text-muted-foreground">
                    Leads counted when they arrived; deals when they were opened.
                </p>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <th class="py-2 pr-4 font-medium">{{ Str::singular($this->level()) }}</th>
                            <th class="py-2 pr-4 text-right font-medium">Spend</th>
                            <th class="py-2 pr-4 text-right font-medium">Leads</th>
                            <th class="py-2 pr-4 text-right font-medium">Cost per lead</th>
                            <th class="py-2 pr-4 text-right font-medium">Qualified</th>
                            <th class="py-2 pr-4 text-right font-medium">Cost per qualified</th>
                            <th class="py-2 pr-4 text-right font-medium">Won</th>
                            <th class="py-2 pr-4 text-right font-medium">Revenue</th>
                            <th class="py-2 text-right font-medium">ROI</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="py-3 pr-4">
                                    @if ($this->canDrill())
                                        <button
                                            type="button"
                                            wire:click="{{ $campaignId === '' ? 'openCampaign' : 'openAdSet' }}('{{ $row->id }}')"
                                            class="text-left font-medium text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
                                        >
                                            {{ $row->name }}
                                        </button>
                                    @else
                                        <span class="font-medium text-foreground">{{ $row->name }}</span>
                                    @endif

                                    @if ($row->hasLeadGap())
                                        {{-- Meta counts a lead at the form; this CRM counts one
                                             when it arrives. A gap means deliveries are being
                                             lost, which is a fault to fix rather than a rounding
                                             difference to hide. --}}
                                        <span class="mt-1 block text-xs text-amber-700 dark:text-amber-300">
                                            Meta reports {{ $row->reportedLeads }} leads — {{ $row->reportedLeads - $row->leads }} never reached the CRM.
                                        </span>
                                    @endif
                                </td>

                                <td class="py-3 pr-4 text-right text-muted-foreground">{{ $this->money($row->spend, $row->currency) }}</td>
                                <td class="py-3 pr-4 text-right text-muted-foreground">{{ $row->leads }}</td>
                                <td class="py-3 pr-4 text-right text-muted-foreground">{{ $this->money($row->costPerLead(), $row->currency) }}</td>
                                <td class="py-3 pr-4 text-right text-muted-foreground">{{ $row->qualified }}</td>
                                <td class="py-3 pr-4 text-right text-muted-foreground">{{ $this->money($row->costPerQualifiedLead(), $row->currency) }}</td>
                                <td class="py-3 pr-4 text-right text-muted-foreground">{{ $row->won }}</td>
                                {{-- The company's currency, not the ad account's:
                                     a business paying Meta in dollars and
                                     invoicing in taka must not see its revenue
                                     labelled with Meta's. --}}
                                <td class="py-3 pr-4 text-right text-muted-foreground">{{ $this->money($row->revenue, $row->revenueCurrency) }}</td>
                                <td class="py-3 text-right">
                                    @if ($row->mixesCurrencies())
                                        {{-- Subtracting dollars from taka gives a
                                             percentage that looks authoritative
                                             and means nothing. --}}
                                        <span
                                            class="cursor-help text-muted-foreground"
                                            title="Spend is in {{ $row->currency }} and revenue in {{ $row->revenueCurrency }}. A return needs both in one currency."
                                        >&mdash;</span>
                                    @elseif ($row->roi() === null)
                                        <span class="text-muted-foreground">&mdash;</span>
                                    @else
                                        <span @class([
                                            'font-medium',
                                            'text-emerald-600 dark:text-emerald-400' => $row->roi() > 0,
                                            'text-destructive' => $row->roi() < 0,
                                            'text-muted-foreground' => $row->roi() == 0,
                                        ])>{{ number_format($row->roi(), 1) }}%</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-6 text-center text-sm text-muted-foreground">
                                    Nothing in this period. Figures appear once the advertising has been read from Meta.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($total->mixesCurrencies())
                <div class="mt-4">
                    <x-alert variant="info">
                        Meta bills this advertising in {{ $total->currency }} and this company counts its revenue in
                        {{ $total->revenueCurrency }}, so return is left unanswered rather than computed across the two.
                        Cost per lead is still shown: it is spend divided by a count, which stays in one currency.
                    </x-alert>
                </div>
            @endif

            <p class="mt-4 max-w-3xl text-xs text-muted-foreground">
                A dash means the question cannot be answered yet rather than that the answer is zero — a campaign that
                has spent and produced no leads has no cost per lead. Revenue counts deals opened in this period from
                leads this advertising brought in; a deal usually closes in a later month than the one that paid for it,
                so a short window understates what advertising eventually earned.
            </p>
        </section>
    </x-settings-shell>
</div>
