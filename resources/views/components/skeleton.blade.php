@props(['shape' => 'rows', 'rows' => 5, 'columns' => 4])

{{-- Shape matches the view being loaded so the page does not jump when data
     arrives. See ViewMode::skeleton(). --}}
<div {{ $attributes->merge(['class' => 'animate-pulse']) }} aria-hidden="true">
    @if ($shape === 'rows')
        <div class="overflow-hidden rounded-xl border border-border">
            <div class="flex gap-4 border-b border-border bg-muted/50 px-4 py-3">
                @for ($column = 0; $column < $columns; $column++)
                    <div class="h-3 flex-1 rounded bg-muted"></div>
                @endfor
            </div>

            @for ($row = 0; $row < $rows; $row++)
                <div class="flex gap-4 border-b border-border px-4 py-4 last:border-b-0">
                    @for ($column = 0; $column < $columns; $column++)
                        <div class="h-3 flex-1 rounded bg-muted"></div>
                    @endfor
                </div>
            @endfor
        </div>
    @elseif ($shape === 'cards')
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @for ($row = 0; $row < $rows; $row++)
                <div class="rounded-xl border border-border p-4">
                    <div class="h-4 w-2/3 rounded bg-muted"></div>
                    <div class="mt-3 h-3 w-full rounded bg-muted"></div>
                    <div class="mt-2 h-3 w-4/5 rounded bg-muted"></div>
                    <div class="mt-4 h-6 w-20 rounded-full bg-muted"></div>
                </div>
            @endfor
        </div>
    @elseif ($shape === 'kanban')
        <div class="flex gap-4 overflow-hidden">
            @for ($column = 0; $column < $columns; $column++)
                <div class="w-72 shrink-0 rounded-xl border border-border bg-muted/30 p-3">
                    <div class="h-3 w-1/2 rounded bg-muted"></div>
                    @for ($row = 0; $row < 3; $row++)
                        <div class="mt-3 rounded-lg border border-border bg-card p-3">
                            <div class="h-3 w-3/4 rounded bg-muted"></div>
                            <div class="mt-2 h-3 w-1/2 rounded bg-muted"></div>
                        </div>
                    @endfor
                </div>
            @endfor
        </div>
    @else
        <div class="space-y-3">
            @for ($row = 0; $row < $rows; $row++)
                <div class="h-3 rounded bg-muted"></div>
            @endfor
        </div>
    @endif

    <span class="sr-only">Loading…</span>
</div>
