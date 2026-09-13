<?php

namespace App\Domain\Reports;

/**
 * One line of a report: what it is grouped by, and what it measures.
 *
 * The two are kept apart rather than merged into one flat array, because a
 * dimension's value is a label to print and a measure's is a number to format,
 * and a renderer that could not tell them apart would have to guess.
 */
readonly class ReportRow
{
    /**
     * @param  array<string, string>  $groups  Dimension key => label.
     * @param  array<string, float|int|null>  $values  Measure key => number.
     */
    public function __construct(
        public array $groups,
        public array $values,
    ) {}

    public function group(string $key): ?string
    {
        return $this->groups[$key] ?? null;
    }

    public function value(string $key): float|int|null
    {
        return $this->values[$key] ?? null;
    }

    /**
     * The row's label, for a chart: every dimension joined.
     */
    public function label(string $separator = ' — '): string
    {
        return implode($separator, $this->groups);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->groups, ...$this->values];
    }
}
