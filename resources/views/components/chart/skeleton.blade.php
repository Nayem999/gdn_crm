@props(['type' => 'bar'])

{{-- Shaped like the chart that is coming, so the page does not jump when the
     figures arrive — the same reasoning as the data-view skeletons. --}}
<div {{ $attributes->merge(['class' => 'animate-pulse rounded-xl border border-border bg-card p-5']) }} aria-hidden="true">
    @if ($type === 'pie')
        <div class="flex flex-wrap items-center gap-6">
            <div class="h-48 w-48 shrink-0 rounded-full bg-muted"></div>
            <div class="min-w-[12rem] flex-1 space-y-2">
                @for ($row = 0; $row < 5; $row++)
                    <div class="h-3 w-full rounded bg-muted"></div>
                @endfor
            </div>
        </div>
    @elseif ($type === 'gauge')
        <div class="flex flex-col items-center gap-3">
            <div class="h-24 w-56 rounded-t-full bg-muted"></div>
            <div class="h-6 w-24 rounded bg-muted"></div>
        </div>
    @elseif ($type === 'line')
        <div class="h-48 w-full rounded bg-muted"></div>
    @else
        <div class="space-y-2">
            @for ($row = 0; $row < 6; $row++)
                <div class="flex items-center gap-3">
                    <div class="h-3 w-40 shrink-0 rounded bg-muted"></div>
                    <div class="h-5 flex-1 rounded bg-muted"></div>
                    <div class="h-3 w-16 shrink-0 rounded bg-muted"></div>
                </div>
            @endfor
        </div>
    @endif
</div>
