<?php

namespace App\Domain\Shared\Filters;

use App\Domain\Shared\Enums\FilterFieldType;
use App\Domain\Shared\Enums\FilterOperator;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a filter tree into query constraints.
 *
 * Conditions are matched against the screen's registered FilterField list, so a
 * tampered request can never filter on an unregistered column, and an unknown
 * field or operator is dropped rather than applied loosely.
 */
class FilterApplier
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, FilterField>  $fields  Keyed by field key.
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function apply(Builder $query, FilterGroup $group, array $fields): Builder
    {
        if ($group->isEmpty()) {
            return $query;
        }

        $query->where(function (Builder $nested) use ($group, $fields) {
            $this->applyGroup($nested, $group, $fields);
        });

        return $query;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, FilterField>  $fields
     */
    private function applyGroup(Builder $query, FilterGroup $group, array $fields): void
    {
        $boolean = $group->matchAny() ? 'or' : 'and';
        $applied = false;

        foreach ($group->conditions as $condition) {
            $field = $fields[$condition->field] ?? null;

            if ($field === null || ! $condition->isUsable($field)) {
                continue;
            }

            $query->where(function (Builder $nested) use ($condition, $field) {
                $this->applyCondition($nested, $condition, $field);
            }, boolean: $applied ? $boolean : 'and');

            $applied = true;
        }

        foreach ($group->groups as $childGroup) {
            if ($childGroup->isEmpty()) {
                continue;
            }

            $query->where(function (Builder $nested) use ($childGroup, $fields) {
                $this->applyGroup($nested, $childGroup, $fields);
            }, boolean: $applied ? $boolean : 'and');

            $applied = true;
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyCondition(Builder $query, FilterCondition $condition, FilterField $field): void
    {
        if ($field->isCustomField()) {
            $this->applyCustomField($query, $condition, $field);

            return;
        }

        $column = $query->qualifyColumn($field->column());
        $value = $condition->value;
        $second = $condition->secondValue;

        match ($condition->operator) {
            FilterOperator::Contains => $query->where($column, 'like', '%'.$value.'%'),
            FilterOperator::NotContains => $query->where(function (Builder $inner) use ($column, $value) {
                // A NULL column is not "containing" the term, so it counts as
                // not containing it rather than dropping out of both sides.
                $inner->whereNull($column)->orWhere($column, 'not like', '%'.$value.'%');
            }),
            FilterOperator::Equals => $query->where($column, '=', $value),
            FilterOperator::NotEquals => $query->where(function (Builder $inner) use ($column, $value) {
                $inner->whereNull($column)->orWhere($column, '!=', $value);
            }),
            FilterOperator::StartsWith => $query->where($column, 'like', $value.'%'),
            FilterOperator::EndsWith => $query->where($column, 'like', '%'.$value),
            FilterOperator::GreaterThan => $query->where($column, '>', $value),
            FilterOperator::LessThan => $query->where($column, '<', $value),
            FilterOperator::GreaterThanOrEqual => $query->where($column, '>=', $value),
            FilterOperator::LessThanOrEqual => $query->where($column, '<=', $value),
            FilterOperator::Between => $this->applyBetween($query, $column, $condition, $field),
            FilterOperator::On => $query->whereDate($column, '=', $value),
            FilterOperator::Before => $query->whereDate($column, '<', $value),
            FilterOperator::After => $query->whereDate($column, '>', $value),
            FilterOperator::LastDays => $query->where($column, '>=', now()->subDays((int) $value)->startOfDay()),
            FilterOperator::In => $query->whereIn($column, $condition->values()),
            FilterOperator::NotIn => $query->where(function (Builder $inner) use ($column, $condition) {
                $inner->whereNull($column)->orWhereNotIn($column, $condition->values());
            }),
            FilterOperator::IsTrue => $query->where($column, true),
            FilterOperator::IsFalse => $query->where(function (Builder $inner) use ($column) {
                // "No" covers an explicit false and a column never set.
                $inner->where($column, false)->orWhereNull($column);
            }),
            FilterOperator::IsEmpty => $query->where(function (Builder $inner) use ($column) {
                $inner->whereNull($column)->orWhere($column, '=', '');
            }),
            FilterOperator::IsNotEmpty => $query->whereNotNull($column)->where($column, '!=', ''),
        };
    }

    /**
     * Operators that are the negation of another one.
     *
     * A custom field's answer lives in another table, and the row may not exist
     * at all. Expressing a negative as NOT EXISTS(the positive match) is what
     * makes "does not contain X" also return records that were never given an
     * answer — the NULL-aware behaviour .ai/rules/data-view.md asks for, which
     * EXISTS(value not like X) would silently lose.
     */
    private const NEGATIONS = [
        FilterOperator::NotContains->value => FilterOperator::Contains,
        FilterOperator::NotEquals->value => FilterOperator::Equals,
        FilterOperator::NotIn->value => FilterOperator::In,
        FilterOperator::IsEmpty->value => null,
        FilterOperator::IsFalse->value => FilterOperator::IsTrue,
    ];

    /**
     * Filter on an answer stored in `custom_field_values`.
     *
     * Scoped to the one field by id, so a condition can only ever read the
     * field it names — the id comes from the screen's own registry, never from
     * the request.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyCustomField(Builder $query, FilterCondition $condition, FilterField $field): void
    {
        $negated = array_key_exists($condition->operator->value, self::NEGATIONS);
        $positive = $negated ? self::NEGATIONS[$condition->operator->value] : $condition->operator;

        $table = $query->getModel()->getTable();
        $keyName = $query->getModel()->getKeyName();
        $morphClass = $query->getModel()->getMorphClass();

        $matches = function (BuilderContract $sub) use ($condition, $field, $positive, $table, $keyName, $morphClass): void {
            $sub->select(DB::raw(1))
                ->from('custom_field_values')
                ->whereColumn('custom_field_values.customizable_id', $table.'.'.$keyName)
                ->where('custom_field_values.customizable_type', $morphClass)
                ->where('custom_field_values.custom_field_id', $field->customFieldId);

            // A null operator means "any answer at all", which is what
            // is empty / is not empty ask.
            if ($positive !== null) {
                $this->applyCustomComparison($sub, $condition, $field, $positive);
            }
        };

        if ($negated) {
            $query->whereNotExists($matches);

            return;
        }

        $query->whereExists($matches);
    }

    /**
     * The comparison itself, against the one value column this field's type
     * uses.
     */
    private function applyCustomComparison(
        BuilderContract $sub,
        FilterCondition $condition,
        FilterField $field,
        FilterOperator $operator,
    ): void {
        $column = 'custom_field_values.'.$field->customFieldColumn;
        $value = $condition->value;

        // A multiselect answer is a JSON list, so "is" and "is any of" are
        // containment tests rather than comparisons. JSON_CONTAINS is available
        // on both MySQL 8 and the MariaDB this installation develops against.
        if ($field->customFieldIsList) {
            $values = in_array($operator, [FilterOperator::In, FilterOperator::NotIn], true)
                ? $condition->values()
                : [$value];

            $sub->where(function (BuilderContract $inner) use ($column, $values) {
                foreach ($values as $one) {
                    $inner->orWhereRaw('JSON_CONTAINS('.$column.', ?)', [json_encode((string) $one)]);
                }
            });

            return;
        }

        match ($operator) {
            FilterOperator::Contains => $sub->where($column, 'like', '%'.$value.'%'),
            FilterOperator::Equals => $sub->where($column, '=', $value),
            FilterOperator::StartsWith => $sub->where($column, 'like', $value.'%'),
            FilterOperator::EndsWith => $sub->where($column, 'like', '%'.$value),
            FilterOperator::GreaterThan => $sub->where($column, '>', $value),
            FilterOperator::LessThan => $sub->where($column, '<', $value),
            FilterOperator::GreaterThanOrEqual => $sub->where($column, '>=', $value),
            FilterOperator::LessThanOrEqual => $sub->where($column, '<=', $value),
            FilterOperator::Between => $field->type === FilterFieldType::Date
                ? $sub->whereDate($column, '>=', $condition->value)->whereDate($column, '<=', $condition->secondValue)
                : $sub->whereBetween($column, [$condition->value, $condition->secondValue]),
            FilterOperator::On => $sub->whereDate($column, '=', $value),
            FilterOperator::Before => $sub->whereDate($column, '<', $value),
            FilterOperator::After => $sub->whereDate($column, '>', $value),
            FilterOperator::LastDays => $sub->where($column, '>=', now()->subDays((int) $value)->startOfDay()),
            FilterOperator::In => $sub->whereIn($column, $condition->values()),
            FilterOperator::IsTrue => $sub->where($column, true),
            FilterOperator::IsNotEmpty => $sub->whereNotNull($column),
            // Everything left is a negation, which never reaches here: it was
            // turned into NOT EXISTS of its positive above.
            default => $sub->whereNotNull($column),
        };
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyBetween(Builder $query, string $column, FilterCondition $condition, FilterField $field): BuilderContract
    {
        $from = $condition->value;
        $to = $condition->secondValue;

        if ($field->type->value === 'date') {
            return $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
        }

        return $query->whereBetween($column, [$from, $to]);
    }
}
