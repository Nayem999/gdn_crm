@props(['chart'])

@php
    $slices = $chart->slices();
@endphp

<figure class="flex flex-wrap items-center gap-6">
    <svg viewBox="0 0 200 200" class="h-48 w-48 shrink-0" role="img"
         aria-label="{{ $chart->measureLabel() }} split {{ count($slices) }} ways">
        @foreach ($slices as $slice)
            @if ($slice['full'])
                {{-- One slice is a circle: an arc of exactly 360 degrees starts
                     and ends at the same point, and SVG draws nothing. --}}
                <circle cx="100" cy="100" r="90" fill="{{ $slice['colour'] }}">
                    <title>{{ $slice['label'] }}: {{ $slice['percent'] }}%</title>
                </circle>
            @else
                <path d="{{ $slice['path'] }}" fill="{{ $slice['colour'] }}">
                    <title>{{ $slice['label'] }}: {{ $slice['percent'] }}%</title>
                </path>
            @endif
        @endforeach
    </svg>

    <figcaption class="min-w-[12rem] flex-1">
        <p class="mb-2 text-sm font-medium text-foreground">{{ $chart->measureLabel() }}</p>
        <ul class="space-y-1 text-sm">
            @foreach ($slices as $slice)
                <li class="flex items-center justify-between gap-3">
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $slice['colour'] }}"></span>
                        <span class="truncate text-muted-foreground" title="{{ $slice['label'] }}">{{ $slice['label'] }}</span>
                    </span>
                    <span class="shrink-0 tabular-nums text-foreground">{{ $slice['percent'] }}%</span>
                </li>
            @endforeach
        </ul>
    </figcaption>
</figure>
