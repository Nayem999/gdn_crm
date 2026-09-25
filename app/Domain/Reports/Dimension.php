<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Enums\DateGrain;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a report can group by.
 *
 * The column is declared here and nowhere else: a report definition carries a
 * dimension **key**, which is matched against the source's list before anything
 * reaches SQL. A definition naming a column directly would be a definition
 * naming a column.
 */
readonly class Dimension
{
    /**
     * @param  string  $key  What a report definition refers to it by.
     * @param  string  $column  The qualified column, e.g. "deals.stage".
     * @param  bool  $isDate  Whether it is bucketed by a DateGrain.
     * @param  array<array-key, string>  $labels  Stored value => what to print.
     * @param  string|null  $join  The join this dimension needs, by key.
     * @param  bool  $dateOnly  A DATE column rather than a stored moment: compared as
     *                          a calendar day, with no timezone shift.
     * @param  string|null  $recordColumn  The id of the record each group is, when a
     *                                     group is one record (an account, a person).
     * @param  class-string<Model>|null  $recordModel
     * @param  string|null  $recordRoute  The named route that shows that record.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $column,
        public bool $isDate = false,
        public array $labels = [],
        public ?string $join = null,
        public bool $dateOnly = false,
        public ?string $recordColumn = null,
        public ?string $recordModel = null,
        public ?string $recordRoute = null,
    ) {}

    /**
     * A dimension whose stored values are already what should be printed.
     */
    public static function plain(string $key, string $label, string $column): self
    {
        return new self($key, $label, $column);
    }

    public static function date(string $key, string $label, string $column, bool $dateOnly = false): self
    {
        return new self($key, $label, $column, isDate: true, dateOnly: $dateOnly);
    }

    /**
     * A dimension whose every group is one record, so a row can link to it.
     *
     * Grouped by the record's id as well as its name: two accounts called
     * "Acme" are two customers, and grouping on the name alone added them up.
     *
     * @param  class-string<Model>  $model
     */
    public static function record(
        string $key,
        string $label,
        string $column,
        string $join,
        string $recordColumn,
        string $model,
        ?string $route = null,
    ): self {
        return new self($key, $label, $column, join: $join, recordColumn: $recordColumn, recordModel: $model, recordRoute: $route);
    }

    public function isRecord(): bool
    {
        return $this->recordColumn !== null;
    }

    /**
     * A dimension whose stored values are codes with names.
     *
     * @param  array<array-key, string>  $labels
     */
    public static function coded(string $key, string $label, string $column, array $labels): self
    {
        return new self($key, $label, $column, labels: $labels);
    }

    /**
     * A dimension that lives on a joined table.
     */
    public static function joined(string $key, string $label, string $column, string $join): self
    {
        return new self($key, $label, $column, join: $join);
    }

    /**
     * The SQL that produces this dimension's bucket.
     */
    public function expression(?DateGrain $grain): string
    {
        if (! $this->isDate) {
            return $this->column;
        }

        return ($grain ?? DateGrain::Month)->expression($this->column);
    }

    /**
     * What to print for a stored value.
     *
     * Codes become names; a null becomes a phrase rather than an empty cell,
     * because a blank in a grouped report reads as a rendering fault rather
     * than as "these rows have nothing here".
     */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '(none)';
        }

        if ($this->labels === []) {
            return (string) $value;
        }

        return $this->labels[$value] ?? (string) $value;
    }
}
