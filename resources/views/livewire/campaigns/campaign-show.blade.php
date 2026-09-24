@php
    use App\Domain\Settings\DisplayTime;
    use App\Domain\Settings\NumberFormat;

    $figures = $this->figures();
@endphp

<div class="space-y-6 pb-16">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300">
                <x-icon name="lucide-megaphone" class="h-5 w-5" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold text-foreground">{{ $campaign->name }}</h1>
                    <x-status-chip :label="$campaign->status()->label()" :color="$campaign->status()->color()" />
                    <x-status-chip :label="$campaign->type()->label()" :color="$campaign->type()->color()" />
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    @if ($campaign->code)
                        <span class="font-medium text-foreground">{{ $campaign->code }}</span> &middot;
                    @endif
                    @if ($campaign->start_date)
                        {{ DisplayTime::date($campaign->start_date) }}
                        &ndash;
                        {{ $campaign->end_date ? DisplayTime::date($campaign->end_date) : 'ongoing' }}
                    @else
                        No dates set
                    @endif
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('update', $campaign)
                <a href="{{ route('campaigns.edit', $campaign) }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">
                    <x-icon name="lucide-pencil" />
                    Edit
                </a>
            @endcan

            @can('delete', $campaign)
                @php($impact = $this->deletionImpact())
                <button
                    type="button"
                    wire:click="delete"
                    wire:confirm="Remove {{ $campaign->name }}? {{ array_sum($impact) > 0 ? $impact['leads'] . ' leads, ' . $impact['contacts'] . ' contacts and ' . $impact['deals'] . ' deals will lose their attribution.' : 'Nothing is attributed to it.' }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-destructive hover:bg-destructive/10"
                >
                    <x-icon name="lucide-trash-2" />
                    Remove
                </button>
            @endcan
        </div>
    </div>

    {{-- What it cost against what it returned. Computed from the records, never
         cached: this page is where somebody comes to find out whether a deal
         moved, and a stored total would be the one thing that had not. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Spent', NumberFormat::format($figures['cost'], 2), $campaign->budget() === null ? 'No budget set' : 'of ' . NumberFormat::format($campaign->budget(), 2)],
            ['Leads', (string) $figures['leads'], $figures['cost_per_lead'] === null ? 'No cost per lead yet' : NumberFormat::format($figures['cost_per_lead'], 2) . ' each'],
            ['Deals', $figures['won'] . ' won of ' . $figures['deals'], 'attributed to this campaign'],
            ['Revenue', NumberFormat::format($figures['revenue'], 2), $figures['roi'] === null ? 'No cost to compare' : NumberFormat::format($figures['roi'], 1) . '% return'],
        ] as [$label, $value, $note])
            <div class="rounded-xl border border-border bg-card p-5">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-foreground">{{ $value }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ $note }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($campaign->description)
                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="text-sm font-semibold text-foreground">About</h2>
                    <p class="mt-2 whitespace-pre-line text-sm text-muted-foreground">{{ $campaign->description }}</p>
                </section>
            @endif

            <section class="rounded-xl border border-border bg-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-foreground">Leads</h2>
                    <a href="{{ route('leads.index') }}" wire:navigate class="text-xs font-medium text-accent hover:underline">
                        All leads
                    </a>
                </div>

                @php($leads = $this->leads())

                @if ($leads->isEmpty())
                    <p class="mt-3 text-sm text-muted-foreground">Nothing has been attributed to this campaign yet.</p>
                @else
                    <ul class="mt-3 divide-y divide-border">
                        @foreach ($leads as $lead)
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="font-medium text-foreground hover:text-accent hover:underline">
                                    {{ $lead->displayName() }}
                                </a>
                                <span class="text-xs text-muted-foreground">{{ $lead->primaryAssignee()?->name }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-xl border border-border bg-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-foreground">Deals</h2>
                    <a href="{{ route('deals.index') }}" wire:navigate class="text-xs font-medium text-accent hover:underline">
                        All deals
                    </a>
                </div>

                @php($deals = $this->deals())

                @if ($deals->isEmpty())
                    <p class="mt-3 text-sm text-muted-foreground">No deals have come from this campaign yet.</p>
                @else
                    <ul class="mt-3 divide-y divide-border">
                        @foreach ($deals as $deal)
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <a href="{{ route('deals.show', $deal) }}" wire:navigate class="font-medium text-foreground hover:text-accent hover:underline">
                                    {{ $deal->name }}
                                </a>
                                <span class="text-xs text-muted-foreground">{{ NumberFormat::format((float) $deal->value, 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <livewire:timeline.record-timeline
                :module="'campaigns'"
                :record="$campaign->id"
                :key="'timeline-campaigns-'.$campaign->id"
            />
        </div>

        <aside class="space-y-6">
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Details</h2>

                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Owner</dt>
                        <dd class="text-foreground">{{ $campaign->owner?->name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Budget used</dt>
                        <dd class="text-foreground">
                            @if ($campaign->budgetUsedPercent() === null)
                                —
                            @else
                                <span @class(['font-medium text-destructive' => $campaign->budgetUsedPercent() > 100])>
                                    {{ NumberFormat::format($campaign->budgetUsedPercent(), 1) }}%
                                </span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Expected revenue</dt>
                        <dd class="text-foreground">
                            {{ $campaign->expected_revenue === null ? '—' : NumberFormat::format((float) $campaign->expected_revenue, 2) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Within its dates</dt>
                        <dd class="text-foreground">{{ $campaign->isWithinDates() ? 'Yes' : 'No' }}</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>
</div>
