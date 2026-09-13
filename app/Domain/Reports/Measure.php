<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Enums\Aggregate;

/**
 * Something a report can count or total.
 *
 * Like a dimension, the column lives here: a definition carries a measure key,
 * and the key is matched against the source before the aggregate is built.
 */
readonly class Measure
{
    /**
     * @param  string|null  $column  Null for a plain row count.
     * @param  string|null  $format  'money', 'number', 'percent' or null.
     * @param  string|null  $join  The join this measure needs, by key.
     */
    public function __construct(
        public string $key,
        public string $label,
        public Aggregate $aggregate,
        public ?string $column = null,
        public ?string $format = null,
        public ?string $join = null,
    ) {}

    public static function count(string $key = 'count', string $label = 'Records'): self
    {
        return new self($key, $label, Aggregate::Count, format: 'number');
    }

    public static function sum(string $key, string $label, string $column, ?string $format = 'number'): self
    {
        return new self($key, $label, Aggregate::Sum, $column, $format);
    }

    public static function money(string $key, string $label, string $column): self
    {
        return new self($key, $label, Aggregate::Sum, $column, 'money');
    }

    public static function average(string $key, string $label, string $column, ?string $format = 'number'): self
    {
        return new self($key, $label, Aggregate::Average, $column, $format);
    }

    /**
     * The aggregate expression.
     *
     * COUNT(*) for a plain count rather than COUNT(column), which would skip
     * rows whose column is null and quietly answer a different question.
     */
    public function expression(): string
    {
        if ($this->aggregate === Aggregate::Count) {
            return $this->column === null
                ? 'COUNT(*)'
                : 'COUNT('.$this->column.')';
        }

        return $this->aggregate->sqlFunction().'('.$this->column.')';
    }

    /**
     * Whether an empty group reads as nought or as nothing at all.
     */
    public function zeroIsMeaningful(): bool
    {
        return $this->aggregate->zeroIsMeaningful();
    }
}
