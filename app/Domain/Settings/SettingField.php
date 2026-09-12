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
     * @param  bool  $live  Whether changing it re-renders the form. Only worth
     *                      setting on a field other fields depend on.
     * @param  array<string, array<int, string>>  $showWhen  Sibling key => the
     *                                                       values of that sibling
     *                                                       that bring this field
     *                                                       into play. Any one
     *                                                       match is enough; an
     *                                                       empty map means always.
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
        public bool $live = false,
        public array $showWhen = [],
    ) {}

    /**
     * Whether this field applies, given what the rest of the group is set to.
     *
     * A group that configures one of several alternatives — an email provider,
     * an SMS driver — has fields that are meaningless unless their alternative
     * is the chosen one. Declaring that here rather than in a view keeps it out
     * of the validation rules and out of the submitted payload as well as off
     * the screen: a credential for a provider you are not using is not edited,
     * not validated, and not written.
     *
     * **Any one named sibling matching is enough.** Mailgun's credentials are
     * wanted when Mailgun is the provider *or* when it is the fallback behind
     * another one, and requiring both would mean neither ever showed.
     *
     * @param  array<string, mixed>  $values  The group's current values.
     */
    public function appliesTo(array $values): bool
    {
        if ($this->showWhen === []) {
            return true;
        }

        foreach ($this->showWhen as $sibling => $allowed) {
            if (in_array($values[$sibling] ?? null, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

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

    /**
     * @param  array<string, array<int, string>>  $showWhen
     */
    public static function text(string $key, string $label, ?string $help = null, array $showWhen = []): self
    {
        return new self($key, $label, SettingType::String, help: $help, showWhen: $showWhen);
    }

    /**
     * @param  array<string, array<int, string>>  $showWhen
     */
    public static function secret(string $key, string $label, ?string $help = null, array $showWhen = []): self
    {
        return new self($key, $label, SettingType::String, secret: true, help: $help, showWhen: $showWhen);
    }

    public static function boolean(string $key, string $label, bool $default = false, ?string $help = null): self
    {
        return new self($key, $label, SettingType::Boolean, default: $default, help: $help);
    }

    /**
     * @param  array<array-key, string>  $options
     * @param  array<string, array<int, string>>  $showWhen
     */
    public static function select(string $key, string $label, array $options, mixed $default = null, ?string $help = null, bool $live = false, array $showWhen = []): self
    {
        return new self($key, $label, SettingType::String, default: $default, help: $help, options: $options, required: true, live: $live, showWhen: $showWhen);
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
