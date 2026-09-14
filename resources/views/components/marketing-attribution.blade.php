@props([
    // Any record using HasMarketingAttribution.
    'record',
    'heading' => 'Marketing attribution',
])

@php
    $attribution = $record->attribution();
    $rows = $attribution->forDisplay();
    $campaign = $record->campaign ?? null;
@endphp

{{-- Drawn only when there is something to say. A panel of eight dashes on every
     lead somebody typed in by hand tells nobody anything, and it would push the
     things that do matter below the fold. --}}
@if ($rows !== [] || $campaign !== null)
    <section class="rounded-xl border border-border bg-card p-5">
        <div class="flex items-center gap-2">
            <x-icon name="lucide-megaphone" class="h-4 w-4 text-muted-foreground" />
            <h2 class="text-sm font-semibold text-foreground">{{ $heading }}</h2>
        </div>

        <dl class="mt-3 space-y-3">
            @if ($campaign !== null)
                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">Campaign</dt>
                    <dd class="mt-1 text-sm">
                        @can('view', $campaign)
                            <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate
                               class="font-medium text-foreground hover:text-accent hover:underline">
                                {{ $campaign->name }}
                            </a>
                        @else
                            <span class="text-foreground">{{ $campaign->name }}</span>
                        @endcan
                    </dd>
                </div>
            @endif

            @foreach ($rows as $label => $value)
                {{-- The campaign name from the advertisement is dropped when the
                     record is linked to a CRM campaign above: two campaign lines
                     saying almost the same thing is how somebody ends up quoting
                     the wrong one. --}}
                @continue($label === 'Campaign' && $campaign !== null)

                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                    <dd class="mt-1 break-words text-sm text-foreground">{{ $value }}</dd>
                </div>
            @endforeach

            @if ($attribution->capturedAt !== null)
                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">First touch</dt>
                    <dd class="mt-1 text-sm text-foreground">
                        {{ \App\Domain\Settings\DisplayTime::dateTime($attribution->capturedAt) }}
                    </dd>
                </div>
            @endif
        </dl>
    </section>
@endif
