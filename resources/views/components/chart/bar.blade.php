@props(['chart'])

@php
    $points = $chart->points();
    $scale = $chart->scale();
@endphp

<figure>
    <figcaption class="mb-4 text-sm font-medium text-foreground">{{ $chart->measureLabel() }}</figcaption>

    {{-- Bars are divs rather than SVG rectangles: the labels wrap, the bars
         reflow at any width, and a browser does the layout. The shapes that
         need real geometry (pie, gauge) are SVG. --}}
    <div class="space-y-2">
        @foreach ($points as $point)
            <div class="flex items-center gap-3">
                <span class="w-40 shrink-0 truncate text-xs text-muted-foreground" title="{{ $point['label'] }}">
                    {{ $point['label'] }}
                </span>
                <div class="h-5 flex-1 overflow-hidden rounded bg-muted">
                    <div class="h-full rounded"
                         style="width: {{ max(0.5, round($point['value'] / $scale * 100, 2)) }}%; background-color: {{ $point['colour'] }}"
                         role="img"
                         aria-label="{{ $point['label'] }}: {{ $point['value'] }}"></div>
                </div>
                <span class="w-24 shrink-0 text-right text-xs tabular-nums text-foreground">
                    {{ number_format($point['value'], floor($point['value']) === $point['value'] ? 0 : 2) }}
                </span>
            </div>
        @endforeach
    </div>
</figure>
