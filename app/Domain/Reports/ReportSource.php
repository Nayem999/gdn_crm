<?php

namespace App\Domain\Reports;

use App\Domain\Shared\Filters\FilterField;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing a report can be about.
 *
 * A source owns four lists — dimensions, measures, joins and filters — and the
 * report engine will use nothing that is not on them. That is the whole
 * security model of the reporting engine: a definition carries **keys**, and a
 * key that is not declared here is dropped rather than passed through. Without
 * that, a report builder is an arbitrary-SQL console with a nice front end.
 *
 * The base query is a closure taking the viewer, so every report is scoped by
 * the same `visibleTo()` the module's own list screen uses. A report is the
 * easiest place in an application to leak a record, because an aggregate does
 * not look like the rows it was drawn from.
 */
readonly class ReportSource
{
    /**
     * @param  Closure(User): Builder<covariant Model>  $query
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     * @param  array<string, ReportJoin>  $joins
     * @param  array<string, FilterField>  $filters
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $table,
        public string $permission,
        private Closure $query,
        public array $dimensions = [],
        public array $measures = [],
        public array $joins = [],
        public array $filters = [],
        public string $description = '',
        public ?string $recordLabel = null,
        public ?string $recordRoute = null,
    ) {}

    /**
     * Whether a row of this source's report can be opened to list the records
     * behind it.
     */
    public function canListRecords(): bool
    {
        return $this->recordLabel !== null;
    }

    /**
     * This source's records, scoped to what the viewer may see.
     *
     * @return Builder<covariant Model>
     */
    public function query(User $viewer): Builder
    {
        return ($this->query)($viewer);
    }

    public function visibleTo(User $viewer): bool
    {
        return $viewer->can($this->permission);
    }

    public function dimension(string $key): ?Dimension
    {
        return $this->dimensions[$key] ?? null;
    }

    public function measure(string $key): ?Measure
    {
        return $this->measures[$key] ?? null;
    }

    public function join(string $key): ?ReportJoin
    {
        return $this->joins[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function dimensionOptions(): array
    {
        return array_map(fn (Dimension $dimension) => $dimension->label, $this->dimensions);
    }

    /**
     * @return array<string, string>
     */
    public function measureOptions(): array
    {
        return array_map(fn (Measure $measure) => $measure->label, $this->measures);
    }
}
