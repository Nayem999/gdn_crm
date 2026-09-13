@props(['chart'])

@php
    $width = 600;
    $height = 200;
    $points = $chart->linePoints($width, $height);
    $polyline = $chart->polyline($width, $height);
    $stroke = \App\Domain\Reports\Charts\ChartData::PALETTE[0];
@endphp

<figure>
    <figcaption class="mb-4 text-sm font-medium text-foreground">{{ $chart->measureLabel() }}</figcaption>

    <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-48 w-full min-w-[32rem]" role="img"
             aria-label="{{ $chart->measureLabel() }} across {{ count($points) }} points"
             preserveAspectRatio="none">
            <polyline
                points="{{ $polyline }}"
                fill="none"
                stroke="{{ $stroke }}"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            @foreach ($points as $point)
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3" fill="{{ $stroke }}">
                    <title>{{ $point['label'] }}: {{ $point['value'] }}</title>
                </circle>
            @endforeach
        </svg>
    </div>

    {{-- The axis as text beneath, not inside the SVG: the viewBox is stretched
         to the container width, which would stretch the letters with it. --}}
    <div class="mt-2 flex justify-between gap-2 text-[10px] text-muted-foreground">
        <span>{{ $points[0]['label'] ?? '' }}</span>
        @if (count($points) > 1)
            <span>{{ $points[count($points) - 1]['label'] }}</span>
        @endif
    </div>
</figure>
