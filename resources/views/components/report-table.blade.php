@props([
    // A ReportResult. The component says a different thing for each of its
    // three nothings — refused, not yet asking anything, and nothing matched —
    // because telling somebody "no data" when the truth is "you may not see
    // this" sends them looking for records that are there.
    'result',
    'sortable' => false,
    // Dimension key => record id => URL, for the groups the reader may open.
    'links' => [],
    // Whether each row offers "the records behind this". Only on the report
    // page: the builder preview, the dashboard and the PDF have nowhere to go.
    'drillable' => false,
])

@php
    $canDrill = $drillable && ($result->source?->canListRecords() ?? false);
@endphp

@php
    /**
     * Measures carry a format, so a money column reads as money and a count as
     * a count. Done here rather than in the runner: how a number is printed is
     * a presentation choice, and the runner's job is the arithmetic.
     */
    $format = function ($value, $measure) {
        if ($value === null) {
            return '—';
        }

        return match ($measure->format) {
            'money' => number_format((float) $value, 2),
            'percent' => number_format((float) $value, 1) . '%',
            default => is_float($value) && floor($value) !== $value
                ? number_format($value, 2)
                : number_format((float) $value),
        };
    };
@endphp

<div class="rounded-xl border border-border bg-card">
    @if ($result->refused)
        <div class="p-10 text-center">
            <x-icon name="lucide-lock" class="mx-auto h-8 w-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold text-foreground">Not yours to read</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                This report is about something you do not have access to. The figures are there; they are just not
                yours to see.
            </p>
        </div>
    @elseif (! $result->isRunnable())
        <div class="p-10 text-center">
            <x-icon name="lucide-table" class="mx-auto h-8 w-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold text-foreground">Nothing asked yet</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                Choose at least one thing to measure. Grouping is optional — without it you get a single row of totals.
            </p>
        </div>
    @elseif (! $result->hasRows())
        <div class="p-10 text-center">
            <x-icon name="lucide-search-x" class="mx-auto h-8 w-8 text-muted-foreground" />
            <h2 class="mt-3 text-base font-semibold text-foreground">Nothing matched</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                The report ran, and no records fell into it. Try loosening the filters.
            </p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                        @foreach ($result->dimensions as $key => $dimension)
                            <th class="px-4 py-2.5 font-medium">
                                @if ($sortable)
                                    <button type="button" wire:click="sortByColumn('{{ $key }}')"
                                            class="inline-flex items-center gap-1 hover:text-foreground">
                                        {{ $dimension->label }}
                                    </button>
                                @else
                                    {{ $dimension->label }}
                                @endif
                            </th>
                        @endforeach
                        @foreach ($result->measures as $key => $measure)
                            <th class="px-4 py-2.5 text-right font-medium">
                                @if ($sortable)
                                    <button type="button" wire:click="sortByColumn('{{ $key }}')"
                                            class="inline-flex items-center gap-1 hover:text-foreground">
                                        {{ $measure->label }}
                                    </button>
                                @else
                                    {{ $measure->label }}
                                @endif
                            </th>
                        @endforeach
                        @if ($canDrill)
                            <th class="w-px px-4 py-2.5"><span class="sr-only">Records</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result->rows as $row)
                        <tr class="border-b border-border/60 last:border-0">
                            @foreach ($result->dimensions as $key => $dimension)
                                @php($url = ($id = $row->id($key)) !== null ? ($links[$key][$id] ?? null) : null)
                                <td class="px-4 py-2 text-foreground">
                                    @if ($url !== null)
                                        <a href="{{ $url }}" wire:navigate class="font-medium text-accent hover:underline">{{ $row->group($key) }}</a>
                                    @else
                                        {{ $row->group($key) }}
                                    @endif
                                </td>
                            @endforeach
                            @foreach ($result->measures as $key => $measure)
                                <td class="px-4 py-2 text-right tabular-nums text-foreground">
                                    {{ $format($row->value($key), $measure) }}
                                </td>
                            @endforeach
                            @if ($canDrill)
                                <td class="px-4 py-2 text-right">
                                    <button type="button"
                                            wire:click="drillInto(@js($row->drill()))"
                                            class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium text-accent hover:bg-muted">
                                        Records
                                        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                @if ($result->measures !== [])
                    <tfoot>
                        <tr class="border-t-2 border-border bg-muted/40 font-semibold">
                            @if ($result->dimensions !== [])
                                <td class="px-4 py-2.5 text-foreground" colspan="{{ count($result->dimensions) }}">
                                    Everything
                                </td>
                            @endif
                            @foreach ($result->measures as $key => $measure)
                                <td class="px-4 py-2.5 text-right tabular-nums text-foreground">
                                    {{ $format($result->totals[$key] ?? null, $measure) }}
                                </td>
                            @endforeach
                            @if ($canDrill)
                                <td></td>
                            @endif
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if ($result->truncated)
            {{-- Said out loud: a cut list that looked complete would have
                 somebody quoting a total that is not the total. --}}
            <p class="border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                Showing the first {{ number_format($result->rowCount()) }} rows. The totals below the table are over
                everything, not just what is shown.
            </p>
        @endif
    @endif
</div>
