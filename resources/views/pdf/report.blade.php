{{-- A report as a printed page.

     Plain tables and inline styles: dompdf supports a narrow slice of CSS, and
     none of the application's Tailwind reaches it. The chart is server-side SVG
     for the same reason — dompdf cannot run JavaScript, so a chart drawn by one
     would be a blank space on every scheduled report. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        .head { border-bottom: 1px solid #d1d5db; padding-bottom: 8px; margin-bottom: 12px; }
        .name { font-size: 16px; font-weight: bold; }
        .muted { color: #6b7280; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: left; border-bottom: 1px solid #d1d5db; padding: 5px 6px; font-size: 9px;
             text-transform: uppercase; color: #6b7280; }
        td { padding: 5px 6px; border-bottom: 1px solid #f3f4f6; }
        .num { text-align: right; }
        tfoot td { border-top: 2px solid #d1d5db; font-weight: bold; }
        .bar-row { padding: 2px 0; }
        .bar-label { width: 30%; font-size: 9px; color: #6b7280; }
        .bar-track { background: #f3f4f6; height: 10px; }
        .bar-fill { height: 10px; }
        .note { margin-top: 10px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="head">
        <div class="name">{{ $report->name }}</div>
        <div class="muted">
            {{ $company?->name }}
            @if ($report->description) &middot; {{ $report->description }} @endif
            &middot; produced {{ $generatedAt->format('j M Y, H:i') }}
        </div>
    </div>

    @if ($result->refused)
        <p>This report is about something the person it was sent for cannot see.</p>
    @elseif (! $result->hasRows())
        <p>The report ran, and no records fell into it.</p>
    @else
        @php($chartData = new \App\Domain\Reports\Charts\ChartData($result, $chart))

        @if ($chartData->isDrawable() && $chart === \App\Domain\Reports\Enums\ChartType::Pie)
            <svg viewBox="0 0 200 200" width="180" height="180">
                @foreach ($chartData->slices() as $slice)
                    @if ($slice['full'])
                        <circle cx="100" cy="100" r="90" fill="{{ $slice['colour'] }}"></circle>
                    @else
                        <path d="{{ $slice['path'] }}" fill="{{ $slice['colour'] }}"></path>
                    @endif
                @endforeach
            </svg>
        @elseif ($chartData->isDrawable())
            {{-- Bars as a table, because dompdf's flexbox support is not
                 something to rely on in a document nobody will see before a
                 customer does. --}}
            <table>
                @foreach ($chartData->points() as $point)
                    <tr class="bar-row">
                        <td class="bar-label">{{ $point['label'] }}</td>
                        <td>
                            <div class="bar-track">
                                <div class="bar-fill"
                                     style="width: {{ max(1, round($point['value'] / $chartData->scale() * 100)) }}%;
                                            background: {{ $point['colour'] }}"></div>
                            </div>
                        </td>
                        <td class="num" style="width: 15%">{{ number_format($point['value'], 2) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        <table>
            <thead>
                <tr>
                    @foreach ($result->dimensions as $dimension)
                        <th>{{ $dimension->label }}</th>
                    @endforeach
                    @foreach ($result->measures as $measure)
                        <th class="num">{{ $measure->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rows as $row)
                    <tr>
                        @foreach ($result->dimensions as $key => $dimension)
                            <td>{{ $row->group($key) }}</td>
                        @endforeach
                        @foreach ($result->measures as $key => $measure)
                            <td class="num">
                                {{ $row->value($key) === null ? '—' : number_format((float) $row->value($key), 2) }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($result->measures !== [])
                <tfoot>
                    <tr>
                        @if ($result->dimensions !== [])
                            <td colspan="{{ count($result->dimensions) }}">Everything</td>
                        @endif
                        @foreach ($result->measures as $key => $measure)
                            <td class="num">
                                {{ ($result->totals[$key] ?? null) === null ? '—' : number_format((float) $result->totals[$key], 2) }}
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>

        @if ($result->truncated)
            <p class="note">
                Showing the first {{ number_format($result->rowCount()) }} rows. The totals are over everything,
                not just what is printed.
            </p>
        @endif
    @endif
</body>
</html>
