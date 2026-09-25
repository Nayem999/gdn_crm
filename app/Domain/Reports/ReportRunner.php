<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Enums\Aggregate;
use App\Domain\Settings\DisplayTime;
use App\Domain\Shared\Filters\FilterApplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
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
     * The records behind one row, one line each, with each measure's own value.
     *
     * The same base query as run() — the viewer's scope, the filters, the
     * period — narrowed by the drill, so the list is exactly what the row
     * counted. A count has no per-record value and is left out; a sum or an
     * average shows the number each record contributed.
     *
     * @param  array<string, string>  $drill  Dimension key => the row's raw value.
     */
    public function records(ReportDefinition $definition, User $viewer, array $drill): ReportRecords
    {
        $source = ReportSources::find($definition->source);

        if ($source === null || ! $source->visibleTo($viewer)) {
            return ReportRecords::refused();
        }

        if (! $source->canListRecords()) {
            return new ReportRecords($source);
        }

        $dimensions = $this->resolveDimensions($definition, $source);
        $measures = array_filter(
            $this->resolveMeasures($definition, $source),
            fn (Measure $measure) => $measure->aggregate !== Aggregate::Count && $measure->column !== null,
        );

        $query = $this->baseQuery($definition, $source, $viewer, $dimensions, $measures, $drill);

        $selects = [$source->table.'.id as r__id', $source->recordLabel.' as r__label'];

        foreach ($measures as $key => $measure) {
            $selects[] = $measure->column.' as '.$this->measureAlias($key);
        }

        $query->selectRaw(implode(', ', $selects));

        $leading = array_key_first($measures);

        if ($leading !== null) {
            // Nulls last, then the biggest contribution first: the answer to
            // "why is this row where it is" is read from the top.
            $query->orderByRaw($measures[$leading]->column.' IS NULL')
                ->orderByRaw($measures[$leading]->column.' desc');
        }

        $rows = $query->orderByDesc($source->table.'.id')->limit(ReportRecords::LIMIT + 1)->get();

        $items = [];

        foreach ($rows->take(ReportRecords::LIMIT) as $row) {
            $values = [];

            foreach ($measures as $key => $measure) {
                $raw = $row->{$this->measureAlias($key)} ?? null;
                $values[$key] = $raw === null ? null : round((float) $raw, 2);
            }

            $items[] = [
                'id' => (int) $row->r__id,
                'label' => trim((string) $row->r__label) === '' ? '(unnamed)' : (string) $row->r__label,
                'url' => $source->recordRoute === null ? null : route($source->recordRoute, (int) $row->r__id),
                'values' => $values,
            ];
        }

        return new ReportRecords($source, $measures, $items, $rows->count() > ReportRecords::LIMIT);
    }

    /**
     * The scoped, filtered and joined query, before grouping.
     *
     * @param  array<string, Dimension>  $dimensions
     * @param  array<string, Measure>  $measures
     * @param  array<string, string>  $drill
     */
    private function baseQuery(
        ReportDefinition $definition,
        ReportSource $source,
        User $viewer,
        array $dimensions,
        array $measures,
        array $drill = [],
    ): QueryBuilder {
        /** @var Builder<covariant \Illuminate\Database\Eloquent\Model> $eloquent */
        $eloquent = $source->query($viewer);

        $this->filters->apply($eloquent, $definition->filters, $source->filters);

        // applyScopes() before getQuery(), or every global scope — soft deletes
        // above all — is dropped and removed records come back into the
        // figures. See .ai/rules/models.md.
        $query = $eloquent->applyScopes()->getQuery();

        foreach ($this->requiredJoins($source, $dimensions, $measures) as $join) {
            $query->leftJoin(DB::raw($join->target()), function ($clause) use ($source, $join): void {
                $clause->on(
                    $source->table.'.'.$join->localColumn,
                    '=',
                    $join->name().'.'.$join->foreignColumn,
                );

                // Declared conditions only — a morph table needs its type, and
                // the value is bound rather than interpolated.
                foreach ($join->conditions as $column => $value) {
                    $clause->where($join->name().'.'.$column, '=', $value);
                }
            });
        }

        $this->applyPeriod($query, $definition, $source);
        $this->applyDrill($query, $definition, $drill);

        return $query;
    }

    /**
     * The date dimension a period is measured on: the one the definition names,
     * else the first date it groups by, else the source's first date.
     *
     * Only ever a declared date dimension — a key the source does not have, or
     * one that is not a date, falls through to the default rather than reaching
     * SQL.
     */
    public function periodDimension(ReportDefinition $definition, ReportSource $source): ?Dimension
    {
        $named = $definition->dateField === null ? null : $source->dimension($definition->dateField);

        if ($named !== null && $named->isDate) {
            return $named;
        }

        foreach ($definition->dimensions as $key) {
            $dimension = $source->dimension($key);

            if ($dimension !== null && $dimension->isDate) {
                return $dimension;
            }
        }

        foreach ($source->dimensions as $dimension) {
            if ($dimension->isDate) {
                return $dimension;
            }
        }

        return null;
    }

    private function applyPeriod(QueryBuilder $query, ReportDefinition $definition, ReportSource $source): void
    {
        $dimension = $this->periodDimension($definition, $source);
        $timezone = DisplayTime::timezone();
        $days = $definition->period->days(Carbon::now($timezone), $definition->dateFrom, $definition->dateTo);

        if ($dimension === null || $days === null) {
            return;
        }

        [$from, $to] = $days;

        // A DATE column holds the office's calendar day already. A stored
        // moment is UTC, where the office's day starts and ends at other hours,
        // so its boundaries are converted rather than compared as dates.
        $app = (string) config('app.timezone', 'UTC');

        if ($from !== null) {
            $query->where($dimension->column, '>=', $dimension->dateOnly
                ? $from->toDateString()
                : $from->copy()->startOfDay()->setTimezone($app)->toDateTimeString());
        }

        if ($to !== null) {
            $query->where($dimension->column, '<=', $dimension->dateOnly
                ? $to->toDateString()
                : $to->copy()->endOfDay()->setTimezone($app)->toDateTimeString());
        }
    }

    /**
     * Narrow to one row's records: each drilled dimension equal to its value.
     *
     * Only dimensions the definition groups by — a drill naming anything else
     * is ignored, the same way an undeclared dimension is. Values are bound.
     *
     * @param  array<string, string>  $drill
     */
    private function applyDrill(QueryBuilder $query, ReportDefinition $definition, array $drill): void
    {
        $source = ReportSources::find($definition->source);

        if ($source === null) {
            return;
        }

        foreach ($drill as $key => $value) {
            if (! in_array($key, $definition->dimensions, true)) {
                continue;
            }

            $dimension = $source->dimension((string) $key);

            if ($dimension === null) {
                continue;
            }

            $expression = $dimension->isRecord() ? (string) $dimension->recordColumn : $dimension->expression($definition->grain);

            if ($value === ReportRow::NONE) {
                $query->whereRaw($expression.' IS NULL');

                continue;
            }

            $query->whereRaw($expression.' = ?', [$dimension->isRecord() ? (int) $value : (string) $value]);
        }
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
            $selects[] = $expression.' as '.$this->dimensionAlias($key);
            // The expression, not the alias: MySQL permits an alias in GROUP BY
            // but the standard does not, and ONLY_FULL_GROUP_BY rejects some
            // shapes that rely on it.
            $groups[] = DB::raw($expression);

            if ($dimension->isRecord()) {
                $selects[] = $dimension->recordColumn.' as '.$this->dimensionAlias($key).'__id';
                $groups[] = DB::raw((string) $dimension->recordColumn);
            }
        }

        foreach ($measures as $key => $measure) {
            $selects[] = $measure->expression().' as '.$this->measureAlias($key);
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
            $selects[] = $measure->expression().' as '.$this->measureAlias($key);
        }

        $row = $query->selectRaw(implode(', ', $selects))->first();

        $totals = [];

        foreach ($measures as $key => $measure) {
            $value = $row?->{$this->measureAlias($key)};

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
            $ids = [];
            $keys = [];

            foreach ($dimensions as $key => $dimension) {
                $raw = $row->{$this->dimensionAlias($key)} ?? null;
                $groups[$key] = $dimension->display($raw);

                if ($dimension->isRecord()) {
                    $id = $row->{$this->dimensionAlias($key).'__id'} ?? null;

                    if ($id !== null) {
                        $ids[$key] = (int) $id;
                    }

                    $keys[$key] = $id === null ? ReportRow::NONE : (string) $id;

                    continue;
                }

                $keys[$key] = $raw === null || $raw === '' ? ReportRow::NONE : (string) $raw;
            }

            foreach ($measures as $key => $measure) {
                $values[$key] = $this->cast($row->{$this->measureAlias($key)} ?? null, $measure);
            }

            $shaped[] = new ReportRow($groups, $values, $ids, $keys);
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
    private function dimensionAlias(string $key): string
    {
        return 'd_'.preg_replace('/[^a-z0-9_]/i', '_', $key);
    }

    /**
     * A measure's alias, prefixed differently from a dimension's: a source may
     * offer both under one key — quotes group by the date they were accepted
     * and count how many were — and a shared alias let the measure overwrite
     * the group, printing "1" where the month should be.
     */
    private function measureAlias(string $key): string
    {
        return 'm_'.preg_replace('/[^a-z0-9_]/i', '_', $key);
    }
}
