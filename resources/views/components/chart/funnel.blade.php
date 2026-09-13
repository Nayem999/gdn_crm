@props(['chart'])

@php
    $bands = $chart->bands();
@endphp

<figure>
    <figcaption class="mb-4 text-sm font-medium text-foreground">{{ $chart->measureLabel() }}</figcaption>

    <div class="space-y-1.5">
        @foreach ($bands as $band)
            <div>
                <div class="flex items-center justify-between gap-3 text-xs">
                    <span class="truncate text-muted-foreground" title="{{ $band['label'] }}">{{ $band['label'] }}</span>
                    <span class="shrink-0 tabular-nums text-foreground">
                        {{ number_format($band['value']) }}
                        @if ($band['drop'] !== null)
                            {{-- The only number anybody actually reads off a
                                 funnel: how much was lost since the band above. --}}
                            <span @class([
                                'ml-1',
                                'text-destructive' => $band['drop'] > 0,
                                'text-emerald-600 dark:text-emerald-400' => $band['drop'] <= 0,
                            ])>
                                {{ $band['drop'] > 0 ? 'down ' : 'up ' }}{{ abs($band['drop']) }}%
                            </span>
                        @endif
                    </span>
                </div>
                <div class="mt-1 flex justify-center">
                    <div class="h-7 rounded"
                         style="width: {{ max(2, $band['width']) }}%; background-color: {{ $band['colour'] }}"
                         role="img"
                         aria-label="{{ $band['label'] }}: {{ $band['value'] }}"></div>
                </div>
            </div>
        @endforeach
    </div>
</figure>
