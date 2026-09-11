<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Enums\SettingType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * One configurable setting, as declared by the registry.
 */
readonly class SettingField
{
    /**
     * @param  string  $key  The name within its group, e.g. "s3_key".
     * @param  array<array-key, string>  $options  Choices, for a select field.
     *                                             Keyed by the stored value,
     *                                             which is an int wherever that
     *                                             value is numeric — PHP will
     *                                             not hold "30" as a string key.
     */
    public function __construct(
        public string $key,
        public string $label,
        public SettingType $type = SettingType::String,
        public bool $secret = false,
        public mixed $default = null,
        public ?string $help = null,
        public array $options = [],
        public bool $required = false,
        public ?string $extraRules = null,
    ) {}

    /**
     * The validation rules this field accepts, built from its own type so a
     * submitted value can never be something the type cannot store.
     *
     * Rules come back as an array rather than a pipe string because Rule::in is
     * the only form that survives an option value containing a comma — a
     * thousands separator, for instance, where the plain "in:a,b,c" string
     * would silently split it into empty choices.
     *
     * @return array<int, string|ValidationRule|In>
     */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable', $this->type->baseRule()];

        if ($this->options !== []) {
            $rules[] = Rule::in(array_keys($this->options));
        }

        if ($this->extraRules !== null) {
            $rules[] = $this->extraRules;
        }

        return $rules;
    }

    public static function text(string $key, string $label, ?string $help = null): self
    {
        return new self($key, $label, SettingType::String, help: $help);
    }

    public static function secret(string $key, string $label, ?string $help = null): self
    {
        return new self($key, $label, SettingType::String, secret: true, help: $help);
    }

    public static function boolean(string $key, string $label, bool $default = false, ?string $help = null): self
    {
        return new self($key, $label, SettingType::Boolean, default: $default, help: $help);
    }

    /**
     * @param  array<array-key, string>  $options
     */
    public static function select(string $key, string $label, array $options, mixed $default = null, ?string $help = null): self
    {
        return new self($key, $label, SettingType::String, default: $default, help: $help, options: $options, required: true);
    }

    /**
     * A select whose values are numbers.
     *
     * It has to declare itself Integer: PHP turns a numeric array key into an
     * int whatever it was written as, so a String field's own "string" rule
     * would reject the very options it offers.
     *
     * @param  array<int, string>  $options
     */
    public static function numberSelect(string $key, string $label, array $options, ?int $default = null, ?string $help = null): self
    {
        return new self($key, $label, SettingType::Integer, default: $default, help: $help, options: $options, required: true);
    }
}
