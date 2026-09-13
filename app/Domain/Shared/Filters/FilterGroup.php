<?php

namespace App\Domain\Shared\Filters;

/**
 * A set of conditions combined with AND or OR, which may nest further groups.
 */
readonly class FilterGroup
{
    public const MATCH_ALL = 'all';

    /**
     * An empty filter tree, in the array shape the builder UI edits.
     *
     * Written once here because three places need the same literal — the data
     * view's property default, a saved view with no filters, and clearing.
     */
    public const EMPTY = ['match' => self::MATCH_ALL, 'conditions' => [], 'groups' => []];

    public const MATCH_ANY = 'any';

    /**
     * @param  array<int, FilterCondition>  $conditions
     * @param  array<int, FilterGroup>  $groups
     */
    public function __construct(
        public string $match = self::MATCH_ALL,
        public array $conditions = [],
        public array $groups = [],
    ) {}

    public function matchAny(): bool
    {
        return $this->match === self::MATCH_ANY;
    }

    public function isEmpty(): bool
    {
        if ($this->conditions !== []) {
            return false;
        }

        foreach ($this->groups as $group) {
            if (! $group->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    public function count(): int
    {
        $count = count($this->conditions);

        foreach ($this->groups as $group) {
            $count += $group->count();
        }

        return $count;
    }

    /**
     * Every condition in this group and its descendants that will actually be
     * applied, which is what the chip row and the filter count should reflect.
     *
     * @param  array<string, FilterField>  $fields
     * @return array<int, FilterCondition>
     */
    public function usableConditions(array $fields): array
    {
        $usable = [];

        foreach ($this->conditions as $condition) {
            $field = $fields[$condition->field] ?? null;

            if ($field !== null && $condition->isUsable($field)) {
                $usable[] = $condition;
            }
        }

        foreach ($this->groups as $group) {
            $usable = [...$usable, ...$group->usableConditions($fields)];
        }

        return $usable;
    }

    /**
     * Rebuild a group from the array shape the builder UI keeps in Livewire
     * state. Unusable rows are dropped here rather than reaching the query.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $conditions = [];

        foreach ((array) ($state['conditions'] ?? []) as $conditionState) {
            if (! is_array($conditionState)) {
                continue;
            }

            $condition = FilterCondition::fromArray($conditionState);

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        $groups = [];

        foreach ((array) ($state['groups'] ?? []) as $groupState) {
            if (is_array($groupState)) {
                $groups[] = self::fromArray($groupState);
            }
        }

        return new self(
            match: ($state['match'] ?? self::MATCH_ALL) === self::MATCH_ANY ? self::MATCH_ANY : self::MATCH_ALL,
            conditions: $conditions,
            groups: $groups,
        );
    }

    /**
     * The same array shape fromArray() reads, so a filter tree can be stored in
     * a column and come back as itself.
     *
     * Added for saved reports (10.1): the data view keeps its filters as the
     * array already and never needed the inverse.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'match' => $this->match,
            'conditions' => array_map(
                fn (FilterCondition $condition) => $condition->toArray(),
                $this->conditions,
            ),
            'groups' => array_map(
                fn (FilterGroup $group) => $group->toArray(),
                $this->groups,
            ),
        ];
    }
}
