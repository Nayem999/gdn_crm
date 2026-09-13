@props([
    // A ReportResult and a ChartType. The component decides for itself whether
    // there is anything to draw and says so plainly when there is not — an
    // empty chart frame with no explanation reads as a broken page.
    'result',
    'type' => 'table',
])

@php
    use App\Domain\Reports\Charts\ChartData;
    use App\Domain\Reports\Enums\ChartType;

    $chartType = $type instanceof ChartType ? $type : (ChartType::tryFrom((string) $type) ?? ChartType::Table);
    $chart = new ChartData($result, $chartType);
@endphp

@if ($chartType === ChartType::Table)
    <x-report-table :result="$result" />
@elseif ($result->refused)
    <div class="rounded-xl border border-border bg-card p-10 text-center">
        <x-icon name="lucide-lock" class="mx-auto h-8 w-8 text-muted-foreground" />
        <h2 class="mt-3 text-base font-semibold text-foreground">Not yours to read</h2>
        <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
            This report is about something you do not have access to.
        </p>
    </div>
@elseif (! $chart->isDrawable())
    <div class="rounded-xl border border-border bg-card p-10 text-center">
        <x-icon name="lucide-{{ $chartType->icon() }}" class="mx-auto h-8 w-8 text-muted-foreground" />
        <h2 class="mt-3 text-base font-semibold text-foreground">Nothing to draw</h2>
        <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
            @if (! $result->hasRows())
                The report ran and no records fell into it.
            @elseif ($result->measures === [])
                Choose something to measure.
            @else
                A {{ strtolower($chartType->label()) }} needs something to group by. The table below shows the figures.
            @endif
        </p>
    </div>
@else
    <div class="rounded-xl border border-border bg-card p-5">
        <x-dynamic-component :component="'chart.' . $chartType->value" :chart="$chart" />
    </div>
@endif
