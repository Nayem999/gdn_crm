<?php

namespace App\Domain\CustomFields\Enums;

use App\Domain\Shared\Enums\FilterFieldType;

/**
 * What kind of answer a custom field collects.
 *
 * This enum is the single place that knows, for each type: which column the
 * value lives in, how a submitted value is validated, how it is cast on the way
 * in and out, and which filter type it behaves as in 4.2. Splitting those four
 * answers across four files is how a type ends up validated as one thing and
 * stored as another.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Currency = 'currency';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Checkbox = 'checkbox';
    case Lookup = 'lookup';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Number',
            self::Currency => 'Currency',
            self::Date => 'Date',
            self::Select => 'Dropdown',
            self::MultiSelect => 'Multi-select',
            self::Checkbox => 'Checkbox',
            self::Lookup => 'Lookup',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Text => 'A single line of text.',
            self::Number => 'A number, with up to two decimal places.',
            self::Currency => 'An amount of money, in the company currency.',
            self::Date => 'A calendar date.',
            self::Select => 'One choice from a list you define.',
            self::MultiSelect => 'Any number of choices from a list you define.',
            self::Checkbox => 'Yes or no.',
            self::Lookup => 'A link to a record in another module.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'type',
            self::Number => 'hash',
            self::Currency => 'banknote',
            self::Date => 'calendar',
            self::Select => 'list',
            self::MultiSelect => 'list-checks',
            self::Checkbox => 'square-check',
            self::Lookup => 'link',
        };
    }

    /**
     * The column in `custom_field_values` this type is stored in.
     *
     * Exactly one column is written per row. See the migration on why this is
     * not a single serialised value.
     */
    public function column(): string
    {
        return match ($this) {
            self::Text, self::Select => 'value_string',
            self::Number, self::Currency => 'value_number',
            self::Date => 'value_date',
            self::Checkbox => 'value_boolean',
            self::MultiSelect => 'value_json',
            self::Lookup => 'value_lookup_id',
        };
    }

    /**
     * Whether the field carries a list of choices the administrator defines.
     */
    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::MultiSelect;
    }

    public function isLookup(): bool
    {
        return $this === self::Lookup;
    }

    /**
     * Whether more than one value can be chosen, which the form and the
     * validation both need to know.
     */
    public function isMultiple(): bool
    {
        return $this === self::MultiSelect;
    }

    /**
     * How this type behaves in the filter builder 4.2 wires it into.
     *
     * Declared here rather than in 4.2 so a type added later cannot be
     * filterable in a way that disagrees with how it is stored. A lookup
     * filters as a select: the choices are records, but the comparison is still
     * "is this one of these ids".
     */
    public function filterType(): FilterFieldType
    {
        return match ($this) {
            self::Text => FilterFieldType::Text,
            self::Number, self::Currency => FilterFieldType::Number,
            self::Date => FilterFieldType::Date,
            self::Select, self::MultiSelect, self::Lookup => FilterFieldType::Select,
            self::Checkbox => FilterFieldType::Boolean,
        };
    }

    /**
     * Turn what was submitted into what the column should hold.
     *
     * Returns null for "no answer", which is what clears a value. An empty
     * string is an absent answer, not the text "": a required field is caught
     * by validation, and an optional one should not keep a blank row.
     *
     * A checkbox is the exception — false is a real answer, not an absent one.
     *
     * @param  array<int, string>  $optionKeys  The choices the definition offers.
     * @return string|float|bool|array<int, string>|int|null
     */
    public function cast(mixed $value, array $optionKeys = []): string|float|bool|array|int|null
    {
        if ($this === self::Checkbox) {
            return (bool) $value;
        }

        if ($this === self::MultiSelect) {
            $chosen = is_array($value) ? $value : [];

            // Intersected with what the definition actually offers, so a
            // submitted option the administrator never defined is dropped
            // rather than stored — the same rule the filter builder follows.
            $chosen = array_values(array_intersect(
                array_map(static fn (mixed $item): string => (string) $item, $chosen),
                $optionKeys,
            ));

            return $chosen === [] ? null : $chosen;
        }

        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return null;
        }

        return match ($this) {
            self::Number, self::Currency => (float) $value,
            self::Lookup => (int) $value,
            self::Date => (string) $value,
            default => (string) $value,
        };
    }

    /**
     * The validation rules for a submitted value of this type.
     *
     * `max:255` on text and select is not arbitrary: value_string is a
     * VARCHAR(255) so that it can carry an index, and a rule that allowed more
     * would fail at the database instead of on the form.
     *
     * @param  array<int, string>  $optionKeys
     * @return array<int, string>
     */
    public function rules(bool $required, array $optionKeys = []): array
    {
        $presence = $required ? 'required' : 'nullable';

        return match ($this) {
            self::Text => [$presence, 'string', 'max:255'],
            // Constrained to the choices the administrator defined, so a
            // submitted value the dropdown never offered is refused rather
            // than stored. Option keys are slugs (see CustomField::optionKey),
            // which is what makes the comma-separated `in` rule safe — the
            // trap SettingField hit with separator options.
            self::Select => $optionKeys === []
                ? [$presence, 'string', 'max:255']
                : [$presence, 'string', 'max:255', 'in:'.implode(',', $optionKeys)],
            self::Number => [$presence, 'numeric'],
            // Money is never negative here: a negative "contract value" is a
            // data-entry slip, and every report that sums it would be wrong.
            self::Currency => [$presence, 'numeric', 'min:0'],
            self::Date => [$presence, 'date'],
            self::MultiSelect => [$presence, 'array'],
            // A required checkbox means "must be ticked" — accepted is the rule
            // that says so; a plain "required" passes on false.
            self::Checkbox => $required ? ['accepted'] : ['boolean'],
            self::Lookup => [$presence, 'integer'],
        };
    }

    /**
     * Rules for each entry of a multi-value field, keyed by the `field.*` form
     * the validator expects. Empty for every single-value type.
     *
     * @param  array<int, string>  $optionKeys
     * @return array<int, string>
     */
    public function itemRules(array $optionKeys = []): array
    {
        if (! $this->isMultiple()) {
            return [];
        }

        return ['string', $optionKeys === [] ? 'string' : 'in:'.implode(',', $optionKeys)];
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
