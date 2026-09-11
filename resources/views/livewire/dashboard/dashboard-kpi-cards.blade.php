@php
    use App\Domain\Shared\UI\ChipPalette;

    $cards = $this->cards;
@endphp

<div>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">At a glance</h2>

        <div class="flex items-center rounded-lg border border-border p-0.5">
            @foreach ($this->periodOptions() as $value => $label)
                <button
                    type="button"
                    wire:click="setPeriod('{{ $value }}')"
                    @class([
                        'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                        'bg-accent/10 text-accent' => $period === $value,
                        'text-muted-foreground hover:text-foreground' => $period !== $value,
                    ])
                    aria-pressed="{{ $period === $value ? 'true' : 'false' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($cards === [])
        <x-empty-state
            icon="lock"
            heading="Nothing to report yet"
            description="Figures appear here once you have access to a module that has some."
        />
    @else
        {{-- auto-fit rather than a fixed column count: how many cards there are
             depends on which modules the viewer can see, and four columns
             strand a fifth card alone on a second row. --}}
        <div
            class="grid gap-4 grid-cols-1 sm:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))]"
            wire:loading.class="opacity-60"
            wire:target="setPeriod"
        >
            @foreach ($cards as $card)
                <a
                    href="{{ $card->href }}"
                    wire:navigate
                    wire:key="kpi-{{ $card->key }}"
                    class="group rounded-xl border border-border bg-card p-5 transition-colors hover:border-accent/50"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium text-muted-foreground">{{ $card->label }}</p>

                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ ChipPalette::classes($card->color) }}">
                            <x-icon :name="'lucide-' . $card->icon" class="h-4 w-4" />
                        </span>
                    </div>

                    <p class="mt-2 flex items-baseline gap-1.5">
                        @if ($card->unit)
                            <span class="text-sm font-medium text-muted-foreground">{{ $card->unit }}</span>
                        @endif
                        <span class="text-2xl font-semibold tabular-nums text-foreground">{{ $card->value }}</span>
                    </p>

                    @if ($card->caption)
                        <p class="mt-1 text-xs text-muted-foreground">{{ $card->caption }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
