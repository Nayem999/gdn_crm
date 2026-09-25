<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Enums\DateGrain;
use App\Domain\Reports\Enums\DatePeriod;
use App\Domain\Shared\Filters\FilterGroup;

/**
 * What a report asks for.
 *
 * Nothing but keys and a filter tree. It is stored in a column, edited by a
 * builder and posted from a browser, so it deliberately cannot express a column
 * name, a table, or a function — the runner resolves every key against the
 * source and drops what it does not recognise.
 */
readonly class ReportDefinition
{
    /**
     * @param  array<int, string>  $dimensions  Dimension keys, outermost first.
     * @param  array<int, string>  $measures  Measure keys, in column order.
     * @param  string|null  $sortBy  A dimension or measure key.
     * @param  int|null  $limit  Rows to return, or null for the runner's cap.
     * @param  string|null  $dateField  A date dimension key the period applies to;
     *                                  null means the source's first date.
     * @param  string|null  $dateFrom  Y-m-d, for a custom period only.
     * @param  string|null  $dateTo  Y-m-d, for a custom period only.
     */
    public function __construct(
        public string $source,
        public array $dimensions = [],
        public array $measures = [],
        public FilterGroup $filters = new FilterGroup,
        public ?DateGrain $grain = DateGrain::Month,
        public ?string $sortBy = null,
        public string $sortDirection = 'desc',
        public ?int $limit = null,
        public DatePeriod $period = DatePeriod::AllTime,
        public ?string $dateField = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            source: (string) ($state['source'] ?? ''),
            dimensions: self::keys($state['dimensions'] ?? []),
            measures: self::keys($state['measures'] ?? []),
            filters: FilterGroup::fromArray(is_array($state['filters'] ?? null) ? $state['filters'] : []),
            grain: DateGrain::tryFrom((string) ($state['grain'] ?? '')) ?? DateGrain::Month,
            sortBy: match (true) {
                ! array_key_exists('sort_by', $state) => null,
                $state['sort_by'] === null || $state['sort_by'] === '' => null,
                default => (string) $state['sort_by'],
            },
            sortDirection: ($state['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            limit: match (true) {
                ! array_key_exists('limit', $state) => null,
                $state['limit'] === null || $state['limit'] === '' => null,
                default => max(1, (int) $state['limit']),
            },
            period: DatePeriod::tryFrom((string) ($state['period'] ?? '')) ?? DatePeriod::AllTime,
            dateField: self::text($state['date_field'] ?? null),
            dateFrom: self::date($state['date_from'] ?? null),
            dateTo: self::date($state['date_to'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'dimensions' => $this->dimensions,
            'measures' => $this->measures,
            'filters' => $this->filters->toArray(),
            'grain' => $this->grain?->value,
            'sort_by' => $this->sortBy,
            'sort_direction' => $this->sortDirection,
            'limit' => $this->limit,
            'period' => $this->period->value,
            'date_field' => $this->dateField,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
        ];
    }

    /**
     * Whether this asks for anything at all.
     *
     * A report with no measure is not a report — it is a list, and the data-view
     * kit already does those better.
     */
    public function isRunnable(): bool
    {
        return $this->source !== '' && $this->measures !== [];
    }

    public function withSource(string $source): self
    {
        // The date field belongs to the old source; the period carries over.
        return new self($source, [], [], new FilterGroup, $this->grain, null, $this->sortDirection, $this->limit, $this->period, null, $this->dateFrom, $this->dateTo);
    }

    /**
     * The same report over a different stretch of time — what a reader picks on
     * the report page without changing the saved report.
     */
    public function withPeriod(DatePeriod $period, ?string $dateField = null, ?string $dateFrom = null, ?string $dateTo = null): self
    {
        return new self(
            $this->source, $this->dimensions, $this->measures, $this->filters, $this->grain,
            $this->sortBy, $this->sortDirection, $this->limit,
            $period, $dateField ?? $this->dateField, self::date($dateFrom), self::date($dateTo),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A Y-m-d string, or null. Only the shape is checked here; DatePeriod
     * refuses a date the calendar does not have.
     */
    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * A list of keys, with anything that is not a non-empty string dropped.
     *
     * @return array<int, string>
     */
    private static function keys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $key) {
            if (is_string($key) && $key !== '') {
                // Deduplicated: the same dimension twice is a GROUP BY that
                // means nothing and a column printed twice.
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }
}
