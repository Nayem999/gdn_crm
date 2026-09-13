<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Enums\DateGrain;
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
        return new self($source, [], [], new FilterGroup, $this->grain, null, $this->sortDirection, $this->limit);
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
