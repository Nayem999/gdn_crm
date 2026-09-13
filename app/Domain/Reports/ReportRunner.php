<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Enums\Aggregate;
use App\Domain\Shared\Filters\FilterApplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a definition into rows.
 *
 * Every key in the definition is resolved against the source's declared lists
 * before it reaches SQL, and anything unrecognised is dropped rather than passed
 * through. That is deliberate and load-bearing: a report builder whose
 * definitions could name columns would be an arbitrary-SQL console.
 *
 * The base query comes from the source scoped to the viewer, so an aggregate
 * can never be drawn from records that person could not open one by one. An
 * aggregate is the easiest kind of leak to miss, because it does not look like
 * the rows behind it.
 */
class ReportRunner
{
    /**
     * The most rows one report returns.
     *
     * A grouped report with a high-cardinality dimension — a report grouped by
     * contact, say — can produce as many rows as the table has records, and
     * nobody reads ten thousand. The cap is reported in the result so a screen
     * can say the list was cut rather than implying it was complete.
     */
    public const MAX_ROWS = 1000;

    public function __construct(private readonly FilterApplier $filters) {}

    public function run(ReportDefinition $definition, User $viewer): ReportResult
    {
        $source = ReportSources::find($definition->source);

        if ($source === null || ! $source->visibleTo($viewer)) {
            // "You may not see this" and "there is nothing" are different
            // answers; the result says which.
            return ReportResult::refused($definition);
        }

        if (! $definition->isRunnable()) {
            return ReportResult::empty($definition, $source);
        }

        $dimensions = $this->resolveDimensions($definition, $source);
        $measures = $this->resolveMeasures($definition, $source);

        if ($measures === []) {
            return ReportResult::empty($definition, $source);
        }

        $query = $this->baseQuery($definition, $source, $viewer, $dimensions, $measures);

        $this->select($query, $dimensions, $measures, $definition);
        $this->sort($query, $dimensions, $measures, $definition);

        $limit = min($definition->limit ?? self::MAX_ROWS, self::MAX_ROWS);

        // One extra, to tell "exactly a full page" from "more than we will
        // show" without a second COUNT query.
        $rows = $query->limit($limit + 1)->get();
        $truncated = $rows->count() > $limit;

        return new ReportResult(
            definition: $definition,
            source: $source,
            dimensions: $dimensions,
            measures: $measures,
            rows: $this->shape($rows->take($limit)->all(), $dimensions, $measures),
            totals: $this->totals($definition, $source, $viewer, $measures),
            truncated: $truncated,
        );
    }

    /**
     * The scoped, filtered and joined query, before grouping.
     *
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     */
    private function baseQuery(
        ReportDefinition $definition,
        ReportSource $source,
        User $viewer,
        array $dimensions,
        array $measures,
    ): QueryBuilder {
        /** @var Builder<covariant \Illuminate\Database\Eloquent\Model> $eloquent */
        $eloquent = $source->query($viewer);

        $this->filters->apply($eloquent, $definition->filters, $source->filters);

        // applyScopes() before getQuery(), or every global scope — soft deletes
        // above all — is dropped and removed records come back into the
        // figures. See .ai/rules/models.md.
        $query = $eloquent->applyScopes()->getQuery();

        foreach ($this->requiredJoins($source, $dimensions, $measures) as $join) {
            $query->leftJoin(
                DB::raw($join->target()),
                $source->table.'.'.$join->localColumn,
                '=',
                $join->name().'.'.$join->foreignColumn,
            );
        }

        return $query;
    }

    /**
     * The joins the chosen dimensions and measures actually need.
     *
     * Only those: a report grouped by stage should not join the users table to
     * fetch a name nobody asked for.
     *
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     * @return array<int, ReportJoin>
     */
    private function requiredJoins(ReportSource $source, array $dimensions, array $measures): array
    {
        $keys = [];

        foreach ($dimensions as $dimension) {
            if ($dimension->join !== null) {
                $keys[$dimension->join] = true;
            }
        }

        foreach ($measures as $measure) {
            if ($measure->join !== null) {
                $keys[$measure->join] = true;
            }
        }

        $joins = [];

        foreach (array_keys($keys) as $key) {
            $join = $source->join($key);

            if ($join !== null) {
                $joins[] = $join;
            }
        }

        return $joins;
    }

    /**
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     */
    private function select(
        QueryBuilder $query,
        array $dimensions,
        array $measures,
        ReportDefinition $definition,
    ): void {
        $selects = [];
        $groups = [];

        foreach ($dimensions as $key => $dimension) {
            $expression = $dimension->expression($definition->grain);
            $selects[] = $expression.' as '.$this->alias($key);
            // The expression, not the alias: MySQL permits an alias in GROUP BY
            // but the standard does not, and ONLY_FULL_GROUP_BY rejects some
            // shapes that rely on it.
            $groups[] = DB::raw($expression);
        }

        foreach ($measures as $key => $measure) {
            $selects[] = $measure->expression().' as '.$this->alias($key);
        }

        $query->selectRaw(implode(', ', $selects));

        if ($groups !== []) {
            $query->groupBy($groups);
        }
    }

    /**
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     */
    private function sort(
        QueryBuilder $query,
        array $dimensions,
        array $measures,
        ReportDefinition $definition,
    ): void {
        $direction = $definition->sortDirection === 'asc' ? 'asc' : 'desc';
        $key = $definition->sortBy;

        if ($key !== null && isset($measures[$key])) {
            $query->orderBy(DB::raw($measures[$key]->expression()), $direction);

            return;
        }

        if ($key !== null && isset($dimensions[$key])) {
            $query->orderBy(DB::raw($dimensions[$key]->expression($definition->grain)), $direction);

            return;
        }

        // Nobody chose: biggest first by the leading measure, which is what
        // somebody means by "show me the report". A date report reads better in
        // date order, so a leading date dimension wins instead.
        $first = array_key_first($dimensions);

        if ($first !== null && $dimensions[$first]->isDate) {
            $query->orderBy(DB::raw($dimensions[$first]->expression($definition->grain)), 'asc');

            return;
        }

        $measure = reset($measures);

        if ($measure !== false) {
            $query->orderBy(DB::raw($measure->expression()), 'desc');
        }
    }

    /**
     * The same measures over the whole filtered set, ungrouped.
     *
     * Run as its own query rather than summed from the rows: a total of the
     * averages is not the average, and a truncated row list would produce a
     * total that did not match the data.
     *
     * @param  array<string, Measure>  $measures
     * @return array<string, float|int|null>
     */
    private function totals(
        ReportDefinition $definition,
        ReportSource $source,
        User $viewer,
        array $measures,
    ): array {
        $query = $this->baseQuery($definition, $source, $viewer, [], $measures);

        $selects = [];

        foreach ($measures as $key => $measure) {
            $selects[] = $measure->expression().' as '.$this->alias($key);
        }

        $row = $query->selectRaw(implode(', ', $selects))->first();

        $totals = [];

        foreach ($measures as $key => $measure) {
            $value = $row?->{$this->alias($key)};

            $totals[$key] = $this->cast($value, $measure);
        }

        return $totals;
    }

    /**
     * @param  array<int, object>  $rows
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     * @return array<int, ReportRow>
     */
    private function shape(array $rows, array $dimensions, array $measures): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $groups = [];
            $values = [];

            foreach ($dimensions as $key => $dimension) {
                $raw = $row->{$this->alias($key)} ?? null;
                $groups[$key] = $dimension->display($raw);
            }

            foreach ($measures as $key => $measure) {
                $values[$key] = $this->cast($row->{$this->alias($key)} ?? null, $measure);
            }

            $shaped[] = new ReportRow($groups, $values);
        }

        return $shaped;
    }

    /**
     * A measure's value as a number, or null when there was nothing to measure.
     *
     * An average of no rows is unknown; printing nought would be a claim
     * nobody made. A count of no rows really is nought.
     */
    private function cast(mixed $value, Measure $measure): float|int|null
    {
        if ($value === null) {
            return $measure->zeroIsMeaningful() ? 0 : null;
        }

        return $measure->aggregate === Aggregate::Count
            ? (int) $value
            : round((float) $value, 2);
    }

    /**
     * @return array<string, Dimension>
     */
    private function resolveDimensions(ReportDefinition $definition, ReportSource $source): array
    {
        $resolved = [];

        foreach ($definition->dimensions as $key) {
            $dimension = $source->dimension($key);

            if ($dimension !== null) {
                $resolved[$key] = $dimension;
            }
        }

        return $resolved;
    }

    /**
     * @return array<string, Measure>
     */
    private function resolveMeasures(ReportDefinition $definition, ReportSource $source): array
    {
        $resolved = [];

        foreach ($definition->measures as $key) {
            $measure = $source->measure($key);

            if ($measure !== null) {
                $resolved[$key] = $measure;
            }
        }

        return $resolved;
    }

    /**
     * A safe column alias built from a key the source declared.
     *
     * Prefixed and stripped rather than quoted: the keys are ours, but this is
     * the one place a key becomes SQL text, and a prefix also keeps a
     * dimension called "count" from colliding with a measure of that name.
     */
    private function alias(string $key): string
    {
        return 'r_'.preg_replace('/[^a-z0-9_]/i', '_', $key);
    }
}
