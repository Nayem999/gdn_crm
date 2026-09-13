<?php

namespace App\Domain\Reports\Enums;

/**
 * How a report is drawn.
 *
 * Every chart is rendered as **server-side SVG**, not by a JavaScript library.
 * Three reasons, and the third is the decisive one:
 *
 *   - it adds no dependency to an application whose stack is fixed;
 *   - a chart in a Livewire page then needs no re-initialisation after an
 *     update, which is the usual source of blank charts;
 *   - **a scheduled report is emailed as a PDF**, and dompdf cannot run
 *     JavaScript. A JS chart would be missing from every one of them.
 */
enum ChartType: string
{
    case Table = 'table';
    case Bar = 'bar';
    case Line = 'line';
    case Pie = 'pie';
    case Funnel = 'funnel';
    case Gauge = 'gauge';

    public function label(): string
    {
        return match ($this) {
            self::Table => 'Table',
            self::Bar => 'Bar chart',
            self::Line => 'Line chart',
            self::Pie => 'Pie chart',
            self::Funnel => 'Funnel',
            self::Gauge => 'Gauge',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Table => 'table',
            self::Bar => 'chart-column',
            self::Line => 'chart-line',
            self::Pie => 'chart-pie',
            self::Funnel => 'filter',
            self::Gauge => 'gauge',
        };
    }

    /**
     * Whether this shape needs a dimension to have anything to draw.
     *
     * A gauge does not: it shows one number against a target, and a report with
     * no grouping is exactly one number.
     */
    public function needsDimension(): bool
    {
        return $this !== self::Table && $this !== self::Gauge;
    }

    /**
     * Whether the shape only makes sense with one measure.
     *
     * A pie divides a whole into parts, and two wholes on one pie is not a
     * chart. A bar chart can carry several series.
     */
    public function singleMeasure(): bool
    {
        return $this === self::Pie || $this === self::Funnel || $this === self::Gauge;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
