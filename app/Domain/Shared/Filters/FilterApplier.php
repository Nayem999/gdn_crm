<?php

namespace App\Domain\Shared\Filters;

use App\Domain\Shared\Enums\FilterOperator;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

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
