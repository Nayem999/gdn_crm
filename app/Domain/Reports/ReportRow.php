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
     * The drill value standing for "this group had no value" — a null cannot
     * travel in a URL and still mean null.
     */
    public const NONE = '__none__';

    /**
     * @param  array<string, string>  $groups  Dimension key => label.
     * @param  array<string, float|int|null>  $values  Measure key => number.
     * @param  array<string, int>  $ids  Dimension key => the record the group is, for record dimensions.
     * @param  array<string, string>  $keys  Dimension key => the raw value, for drilling into the group.
     */
    public function __construct(
        public array $groups,
        public array $values,
        public array $ids = [],
        public array $keys = [],
    ) {}

    public function id(string $key): ?int
    {
        return $this->ids[$key] ?? null;
    }

    /**
     * What to drill by to reach exactly this row's records.
     *
     * @return array<string, string>
     */
    public function drill(): array
    {
        return $this->keys;
    }

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
