<?php

namespace App\Domain\Reports;

/**
 * The records behind one row of a report — what "why is this customer at the
 * top?" is answered with.
 *
 * Drawn through the same scoped, filtered, period-bound query as the report
 * itself, so the list always adds up to the row that was opened.
 */
readonly class ReportRecords
{
    /**
     * The most records one drill lists.
     */
    public const LIMIT = 100;

    /**
     * @param  array<string, Measure>  $measures  The measures shown per record.
     * @param  array<int, array{id: int, label: string, url: ?string, values: array<string, float|int|null>}>  $items
     */
    public function __construct(
        public ?ReportSource $source,
        public array $measures = [],
        public array $items = [],
        public bool $truncated = false,
        public bool $refused = false,
    ) {}

    public static function refused(): self
    {
        return new self(null, refused: true);
    }
}
