<?php

namespace App\Domain\Shared\Filters;

use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\FilterValueMode;

readonly class FilterCondition
{
    /**
     * @param  array<int, string>  $selected  Choices for "is any of" style operators.
     */
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public mixed $value = null,
        public mixed $secondValue = null,
        public array $selected = [],
    ) {}

    /**
     * Whether this condition carries enough to be worth applying. A half-filled
     * row in the builder UI is ignored rather than matching everything.
     */
    public function isUsable(FilterField $field): bool
    {
        if (! in_array($this->operator, $field->type->operators(), true)) {
            return false;
        }

        return match ($this->operator->valueMode()) {
            FilterValueMode::None => true,
            FilterValueMode::Single => $this->filled($this->value),
            FilterValueMode::Pair => $this->filled($this->value) && $this->filled($this->secondValue),
            FilterValueMode::Multiple => $this->values() !== [],
        };
    }

    /**
     * @return array<int, string>
     */
    public function values(): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $value) => (string) $value, $this->selected),
            fn (string $value) => $value !== ''
        ));
    }

    /**
     * A short human phrase for a filter chip, e.g. "Stage is any of Open, Won".
     */
    public function summary(FilterField $field): string
    {
        $label = strtolower($this->operator->label());

        return match ($this->operator->valueMode()) {
            FilterValueMode::None => "{$field->label} {$label}",
            FilterValueMode::Pair => "{$field->label} {$label} {$this->display($this->value)} and {$this->display($this->secondValue)}",
            FilterValueMode::Multiple => "{$field->label} {$label} ".implode(', ', array_map(
                fn (string $value) => $field->options[$value] ?? $value,
                $this->values()
            )),
            FilterValueMode::Single => "{$field->label} {$label} ".$this->display(
                $field->options[(string) $this->value] ?? $this->value
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): ?self
    {
        $operator = FilterOperator::tryFrom((string) ($state['operator'] ?? ''));
        $field = (string) ($state['field'] ?? '');

        if ($operator === null || $field === '') {
            return null;
        }

        return new self(
            field: $field,
            operator: $operator,
            value: $state['value'] ?? null,
            secondValue: $state['second_value'] ?? null,
            selected: is_array($state['selected'] ?? null) ? $state['selected'] : [],
        );
    }

    /**
     * The same array shape fromArray() reads.
     *
     * `second_value`, not `value2` — this has to round-trip through
     * fromArray(), and the two spellings would quietly lose the second half of
     * every "is between" condition.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator->value,
            'value' => $this->value,
            'second_value' => $this->secondValue,
            'selected' => $this->selected,
        ];
    }

    private function display(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
