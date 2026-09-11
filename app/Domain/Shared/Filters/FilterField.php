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
    ) {}

    /**
     * The database column this field filters, defaulting to the field key.
     */
    public function column(): string
    {
        return $this->column ?? $this->key;
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
