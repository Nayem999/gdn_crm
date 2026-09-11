<?php

namespace App\Domain\Shared\Filters;

use App\Domain\Shared\Enums\FilterFieldType;

/**
 * A field a list screen allows filtering on.
 *
 * Only registered fields can be filtered, so a submitted condition can never
 * name an arbitrary column.
 */
readonly class FilterField
{
    /**
     * @param  array<array-key, string>  $options  Choices for a select field.
     *                                             Keyed by the stored value, which
     *                                             is an int wherever that value is
     *                                             numeric — PHP will not hold a
     *                                             numeric string as an array key.
     */
    public function __construct(
        public string $key,
        public string $label,
        public FilterFieldType $type = FilterFieldType::Text,
        public array $options = [],
        public ?string $column = null,
        /**
         * Set when this field is a custom field (4.1), whose answers live in
         * `custom_field_values` rather than on the model's own table. The
         * applier reaches them through an EXISTS subquery — see
         * FilterApplier::applyCustomField.
         */
        public ?int $customFieldId = null,
        /** Which value_* column that field's type is stored in. */
        public ?string $customFieldColumn = null,
        /** Whether the answer is a list, which is a containment test. */
        public bool $customFieldIsList = false,
    ) {}

    /**
     * The database column this field filters, defaulting to the field key.
     */
    public function column(): string
    {
        return $this->column ?? $this->key;
    }

    /**
     * Whether this field's answers live in `custom_field_values`.
     */
    public function isCustomField(): bool
    {
        return $this->customFieldId !== null && $this->customFieldColumn !== null;
    }

    public static function text(string $key, string $label, ?string $column = null): self
    {
        return new self($key, $label, FilterFieldType::Text, column: $column);
    }

    public static function number(string $key, string $label, ?string $column = null): self
    {
        return new self($key, $label, FilterFieldType::Number, column: $column);
    }

    public static function date(string $key, string $label, ?string $column = null): self
    {
        return new self($key, $label, FilterFieldType::Date, column: $column);
    }

    /**
     * @param  array<array-key, string>  $options
     */
    public static function select(string $key, string $label, array $options, ?string $column = null): self
    {
        return new self($key, $label, FilterFieldType::Select, $options, $column);
    }

    public static function boolean(string $key, string $label, ?string $column = null): self
    {
        return new self($key, $label, FilterFieldType::Boolean, column: $column);
    }
}
