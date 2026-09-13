<?php

namespace App\Domain\Reports;

/**
 * What a report produced.
 *
 * Three different nothings, kept distinct because a screen has to say a
 * different thing for each: **refused** (you may not see this source),
 * **not runnable** (you have not asked for a measure yet), and a real run that
 * matched no records. Collapsing them into an empty array would have a screen
 * telling somebody there is no data when the truth is they cannot see it.
 */
readonly class ReportResult
{
    /**
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     * @param  array<int, ReportRow>  $rows
     * @param  array<string, float|int|null>  $totals
     */
    public function __construct(
        public ReportDefinition $definition,
        public ?ReportSource $source,
        public array $dimensions = [],
        public array $measures = [],
        public array $rows = [],
        public array $totals = [],
        public bool $truncated = false,
        public bool $refused = false,
    ) {}

    public static function refused(ReportDefinition $definition): self
    {
        return new self($definition, null, refused: true);
    }

    public static function empty(ReportDefinition $definition, ReportSource $source): self
    {
        return new self($definition, $source);
    }

    public function hasRows(): bool
    {
        return $this->rows !== [];
    }

    public function isRunnable(): bool
    {
        return ! $this->refused && $this->measures !== [];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * The header line: dimension labels, then measure labels.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        $columns = [];

        foreach ($this->dimensions as $key => $dimension) {
            $columns[$key] = $dimension->label;
        }

        foreach ($this->measures as $key => $measure) {
            $columns[$key] = $measure->label;
        }

        return $columns;
    }

    /**
     * The rows as plain arrays, for an export or a chart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (ReportRow $row) => $row->toArray(), $this->rows);
    }

    /**
     * One measure's values across the rows, for a chart series.
     *
     * @return array<int, float|int|null>
     */
    public function series(string $measureKey): array
    {
        return array_map(fn (ReportRow $row) => $row->value($measureKey), $this->rows);
    }

    /**
     * The row labels, for a chart's axis.
     *
     * @return array<int, string>
     */
    public function labels(): array
    {
        return array_map(fn (ReportRow $row) => $row->label(), $this->rows);
    }
}
