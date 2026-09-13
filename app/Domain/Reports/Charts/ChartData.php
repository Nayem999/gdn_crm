<?php

namespace App\Domain\Reports\Charts;

use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\ReportResult;

/**
 * A report turned into the numbers a chart needs, with the geometry already
 * worked out.
 *
 * Done in PHP rather than in the template because a Blade file full of
 * trigonometry is a Blade file nobody can test. This class is the thing the
 * chart tests assert on; the templates only place what it hands them.
 */
class ChartData
{
    /**
     * The palette, in the order slices and series take it.
     *
     * Hex rather than Tailwind classes: these go into SVG `fill` attributes,
     * and a PDF renderer never sees the stylesheet. They are the same hues the
     * chip palette uses, so a chart and a status chip agree about what
     * "emerald" looks like.
     *
     * @var array<int, string>
     */
    public const PALETTE = [
        '#6366f1', // indigo
        '#10b981', // emerald
        '#f59e0b', // amber
        '#ef4444', // rose
        '#06b6d4', // cyan
        '#8b5cf6', // violet
        '#ec4899', // fuchsia
        '#14b8a6', // teal
        '#f97316', // orange
        '#3b82f6', // blue
    ];

    /**
     * How many slices a pie draws before the rest become "everything else".
     *
     * Beyond this the labels overlap into mush and the chart says less than the
     * table it came from.
     */
    public const MAX_SLICES = 8;

    public function __construct(
        private readonly ReportResult $result,
        private readonly ChartType $type,
    ) {}

    public function type(): ChartType
    {
        return $this->type;
    }

    /**
     * Whether there is anything to draw.
     *
     * Distinct from the result being empty: a bar chart of a report with no
     * dimension has one bar and nothing to put on the axis, which is a table's
     * job rather than a chart's.
     */
    public function isDrawable(): bool
    {
        if (! $this->result->hasRows() || $this->measureKey() === null) {
            return false;
        }

        return ! $this->type->needsDimension() || $this->result->dimensions !== [];
    }

    /**
     * The measure being drawn: the first one, since every shape here draws one
     * series at a time.
     */
    public function measureKey(): ?string
    {
        $key = array_key_first($this->result->measures);

        return $key === null ? null : (string) $key;
    }

    public function measureLabel(): string
    {
        $key = $this->measureKey();

        return $key === null ? '' : $this->result->measures[$key]->label;
    }

    /**
     * The points, biggest first for the shapes that want that, and capped.
     *
     * @return array<int, array{label: string, value: float, colour: string}>
     */
    public function points(): array
    {
        $key = $this->measureKey();

        if ($key === null) {
            return [];
        }

        $points = [];

        foreach ($this->result->rows as $row) {
            $points[] = [
                'label' => $row->label(),
                // Null is nothing to draw rather than nought — but a chart has
                // to place something, and nought is the only honest position
                // for "no value" on an axis that starts there.
                'value' => (float) ($row->value($key) ?? 0),
            ];
        }

        if ($this->type === ChartType::Pie || $this->type === ChartType::Funnel) {
            usort($points, fn (array $a, array $b) => $b['value'] <=> $a['value']);
            $points = $this->collapseTail($points);
        }

        $coloured = [];

        foreach (array_values($points) as $index => $point) {
            $coloured[] = [...$point, 'colour' => self::PALETTE[$index % count(self::PALETTE)]];
        }

        return $coloured;
    }

    /**
     * Everything past MAX_SLICES gathered into one slice.
     *
     * Gathered rather than dropped: a pie whose slices did not add up to the
     * total would be a lie told in a picture.
     *
     * @param  array<int, array{label: string, value: float}>  $points
     * @return array<int, array{label: string, value: float}>
     */
    private function collapseTail(array $points): array
    {
        if (count($points) <= self::MAX_SLICES) {
            return $points;
        }

        $kept = array_slice($points, 0, self::MAX_SLICES - 1);
        $rest = array_slice($points, self::MAX_SLICES - 1);

        $kept[] = [
            'label' => 'Everything else ('.count($rest).')',
            'value' => array_sum(array_column($rest, 'value')),
        ];

        return $kept;
    }

    public function total(): float
    {
        return array_sum(array_column($this->points(), 'value'));
    }

    public function max(): float
    {
        $values = array_column($this->points(), 'value');

        return $values === [] ? 0.0 : (float) max($values);
    }

    /**
     * The tallest bar's height, never nought — a chart of all-zero rows still
     * has to divide by something.
     */
    public function scale(): float
    {
        $max = $this->max();

        return $max > 0 ? $max : 1.0;
    }

    /**
     * Pie slices as SVG arc paths.
     *
     * A single point becomes a full circle rather than an arc: an arc of
     * exactly 360 degrees starts and ends at the same coordinates, and SVG
     * draws nothing at all.
     *
     * @return array<int, array{label: string, value: float, colour: string, path: string, percent: float, full: bool}>
     */
    public function slices(float $cx = 100, float $cy = 100, float $radius = 90): array
    {
        $points = $this->points();
        $total = $this->total();

        if ($points === [] || $total <= 0) {
            return [];
        }

        $slices = [];
        $angle = -M_PI / 2; // Start at twelve o'clock, where a reader expects.

        foreach ($points as $point) {
            $share = $point['value'] / $total;
            $sweep = $share * 2 * M_PI;
            $end = $angle + $sweep;

            $slices[] = [
                ...$point,
                'percent' => round($share * 100, 1),
                'full' => $share >= 0.9999,
                'path' => $share >= 0.9999
                    ? ''
                    : $this->arcPath($cx, $cy, $radius, $angle, $end, $sweep > M_PI),
            ];

            $angle = $end;
        }

        return $slices;
    }

    private function arcPath(float $cx, float $cy, float $radius, float $from, float $to, bool $largeArc): string
    {
        $x1 = $cx + $radius * cos($from);
        $y1 = $cy + $radius * sin($from);
        $x2 = $cx + $radius * cos($to);
        $y2 = $cy + $radius * sin($to);

        return sprintf(
            'M %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f Z',
            $cx, $cy, $x1, $y1, $radius, $radius, $largeArc ? 1 : 0, $x2, $y2
        );
    }

    /**
     * The points of a line, as SVG coordinates.
     *
     * @return array<int, array{x: float, y: float, label: string, value: float}>
     */
    public function linePoints(float $width = 600, float $height = 200, float $padding = 10): array
    {
        $points = $this->points();
        $count = count($points);

        if ($count === 0) {
            return [];
        }

        $usableWidth = $width - $padding * 2;
        $usableHeight = $height - $padding * 2;
        $scale = $this->scale();

        $plotted = [];

        foreach (array_values($points) as $index => $point) {
            // A single point sits in the middle rather than dividing by zero.
            $x = $count === 1
                ? $width / 2
                : $padding + ($index / ($count - 1)) * $usableWidth;

            $plotted[] = [
                'x' => round($x, 2),
                'y' => round($padding + $usableHeight - ($point['value'] / $scale) * $usableHeight, 2),
                'label' => $point['label'],
                'value' => $point['value'],
            ];
        }

        return $plotted;
    }

    /**
     * The `points` attribute of an SVG polyline.
     */
    public function polyline(float $width = 600, float $height = 200, float $padding = 10): string
    {
        return implode(' ', array_map(
            fn (array $point) => $point['x'].','.$point['y'],
            $this->linePoints($width, $height, $padding),
        ));
    }

    /**
     * Funnel bands: each stage's width as a share of the widest.
     *
     * Against the widest rather than against the first, because a funnel whose
     * second stage is larger than its first — which happens, when records are
     * created part-way down — would otherwise draw a band wider than the chart.
     *
     * @return array<int, array{label: string, value: float, colour: string, width: float, drop: float|null}>
     */
    public function bands(): array
    {
        $points = $this->points();
        $scale = $this->scale();
        $bands = [];
        $previous = null;

        foreach ($points as $point) {
            $bands[] = [
                ...$point,
                'width' => round($point['value'] / $scale * 100, 2),
                // How much was lost since the band above, which is the only
                // number anybody actually reads off a funnel.
                'drop' => $previous === null || $previous <= 0
                    ? null
                    : round(($previous - $point['value']) / $previous * 100, 1),
            ];

            $previous = $point['value'];
        }

        return $bands;
    }

    /**
     * A gauge: one number against a target, as a fraction between 0 and 1.
     *
     * Clamped, because a gauge needle past its own dial is a drawing fault, and
     * the figure is printed beside it anyway.
     */
    public function gaugeFraction(?float $target = null): float
    {
        $value = $this->gaugeValue();
        $target ??= $this->max();

        if ($target <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $value / $target));
    }

    /**
     * The number a gauge shows: the report's total for the measure.
     */
    public function gaugeValue(): float
    {
        $key = $this->measureKey();

        if ($key === null) {
            return 0.0;
        }

        return (float) ($this->result->totals[$key] ?? 0);
    }

    /**
     * The arc a gauge draws, as an SVG path over a half circle.
     */
    public function gaugeArc(float $fraction, float $cx = 100, float $cy = 100, float $radius = 80): string
    {
        $fraction = max(0.0, min(1.0, $fraction));

        if ($fraction <= 0) {
            return '';
        }

        $from = M_PI;
        $to = M_PI + $fraction * M_PI;

        $x1 = $cx + $radius * cos($from);
        $y1 = $cy + $radius * sin($from);
        $x2 = $cx + $radius * cos($to);
        $y2 = $cy + $radius * sin($to);

        return sprintf(
            'M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f',
            $x1, $y1, $radius, $radius, $fraction > 0.5 ? 1 : 0, $x2, $y2
        );
    }
}
