<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Enums\FilterFieldType;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\Filters\FilterGroup;

/**
 * Editing a tree of conditions in a Livewire screen.
 *
 * Extracted from `WithDataView` when the workflow builder needed the same
 * thing. Both edit the same structure with the same Blade component, so a
 * second implementation would be two sets of add/remove semantics that have to
 * agree — and would not, the first time somebody fixed a bug in one of them.
 *
 * A using class supplies `conditionFields()`; a list screen answers with the
 * columns it filters on, the workflow builder with the module the workflow
 * watches. `conditionsChanged()` is the hook for whatever must happen after an
 * edit — a list screen resets its page; the builder has no page to reset.
 *
 * The using class declares `public array $filters` itself, rather than
 * inheriting it from here, because a list screen puts `#[Url]` on it — a
 * filtered list has to be linkable — and the workflow builder must not. The
 * name is fixed by `<x-filter-builder>`, which binds to that path: the price of
 * it being a plain presentational component rather than one that knows about
 * Livewire state.
 */
trait EditsConditions
{
    /**
     * The fields conditions can be built on.
     *
     * @return array<int, FilterField>
     */
    abstract public function conditionFields(): array;

    /**
     * Called after any change to the tree.
     */
    protected function conditionsChanged(): void
    {
        // Nothing by default.
    }

    public function filterGroup(): FilterGroup
    {
        return FilterGroup::fromArray($this->filters);
    }

    /**
     * @return array<string, FilterField>
     */
    public function filterFieldMap(): array
    {
        return collect($this->conditionFields())->keyBy('key')->all();
    }

    /**
     * @return array<string, string>
     */
    public function operatorOptionsFor(string $fieldKey): array
    {
        $field = $this->filterFieldMap()[$fieldKey] ?? null;

        return $field === null ? [] : FilterOperator::optionsFor($field->type);
    }

    public function fieldType(string $fieldKey): FilterFieldType
    {
        $field = $this->filterFieldMap()[$fieldKey] ?? null;

        return $field === null ? FilterFieldType::Text : $field->type;
    }

    public function addCondition(?int $groupIndex = null): void
    {
        $first = collect($this->conditionFields())->first();

        if ($first === null) {
            return;
        }

        $condition = [
            'field' => $first->key,
            'operator' => $first->type->operators()[0]->value,
            'value' => null,
            'second_value' => null,
            'selected' => [],
        ];

        if ($groupIndex === null) {
            $this->filters['conditions'][] = $condition;

            return;
        }

        $this->filters['groups'][$groupIndex]['conditions'][] = $condition;
    }

    public function removeCondition(int $index, ?int $groupIndex = null): void
    {
        if ($groupIndex === null) {
            unset($this->filters['conditions'][$index]);
            $this->filters['conditions'] = array_values($this->filters['conditions']);
        } else {
            unset($this->filters['groups'][$groupIndex]['conditions'][$index]);
            $this->filters['groups'][$groupIndex]['conditions'] = array_values(
                $this->filters['groups'][$groupIndex]['conditions']
            );
        }

        $this->conditionsChanged();
    }

    public function addFilterGroup(): void
    {
        $this->filters['groups'][] = ['match' => FilterGroup::MATCH_ANY, 'conditions' => [], 'groups' => []];
        $this->addCondition(count($this->filters['groups']) - 1);
    }

    public function removeFilterGroup(int $groupIndex): void
    {
        unset($this->filters['groups'][$groupIndex]);
        $this->filters['groups'] = array_values($this->filters['groups']);
        $this->conditionsChanged();
    }

    public function clearFilters(): void
    {
        $this->filters = FilterGroup::EMPTY;
        $this->conditionsChanged();
    }

    /**
     * Keep the operator legal when the chosen field changes type.
     *
     * Without this, switching a condition from a date to a number leaves "is
     * before" selected against a number field, and the condition is silently
     * dropped when it reaches the applier.
     */
    protected function normaliseConditions(): void
    {
        $fields = $this->filterFieldMap();

        $fix = function (array $condition) use ($fields): array {
            $field = $fields[$condition['field'] ?? ''] ?? null;

            if ($field === null) {
                return $condition;
            }

            $allowed = array_map(fn (FilterOperator $operator) => $operator->value, $field->type->operators());

            if (! in_array($condition['operator'] ?? '', $allowed, true)) {
                $condition['operator'] = $allowed[0];
                $condition['value'] = null;
                $condition['second_value'] = null;
                $condition['selected'] = [];
            }

            return $condition;
        };

        $this->filters['conditions'] = array_map($fix, (array) ($this->filters['conditions'] ?? []));

        foreach ((array) ($this->filters['groups'] ?? []) as $index => $group) {
            $this->filters['groups'][$index]['conditions'] = array_map(
                $fix,
                (array) ($group['conditions'] ?? [])
            );
        }
    }
}
