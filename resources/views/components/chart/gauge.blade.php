@props(['chart', 'target' => null])

@php
    $value = $chart->gaugeValue();
    $fraction = $chart->gaugeFraction($target);
    $arc = $chart->gaugeArc($fraction);
    $stroke = \App\Domain\Reports\Charts\ChartData::PALETTE[0];
@endphp

<figure class="flex flex-col items-center">
    <svg viewBox="0 0 200 120" class="h-32 w-64" role="img"
         aria-label="{{ $chart->measureLabel() }}: {{ $value }}">
        {{-- The dial behind, the reading in front. Both half circles, so the
             reading is a fraction of one hundred and eighty degrees. --}}
        <path d="{{ $chart->gaugeArc(1) }}" fill="none" stroke="currentColor"
              class="text-muted" stroke-width="14" stroke-linecap="round" />
        @if ($arc !== '')
            <path d="{{ $arc }}" fill="none" stroke="{{ $stroke }}" stroke-width="14" stroke-linecap="round" />
        @endif
    </svg>

    <figcaption class="-mt-6 text-center">
        <p class="text-3xl font-semibold tabular-nums text-foreground">
            {{ number_format($value, floor($value) === $value ? 0 : 2) }}
        </p>
        <p class="mt-1 text-sm text-muted-foreground">{{ $chart->measureLabel() }}</p>
        @if ($target !== null && $target > 0)
            <p class="mt-1 text-xs text-muted-foreground">
                {{ round($fraction * 100) }}% of {{ number_format((float) $target) }}
            </p>
        @endif
    </figcaption>
</figure>
