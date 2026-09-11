<?php

namespace App\Domain\CustomFields;

use App\Domain\CustomFields\Enums\VisibilityOperator;

/**
 * When a field is shown on the form.
 *
 * One condition against one other field on the same form — which may be a
 * standard field (`status`) or another custom field (`cf:industry_sector`).
 *
 * **Evaluated on the server, mirrored in the browser.** The browser decides
 * what a person sees, and the server decides what is validated and stored; if
 * those two disagree, a hidden field can still be required and the form becomes
 * unsubmittable with no visible error. `matches()` is the single definition and
 * `toArray()` is what the Alpine mirror is handed, so the two cannot drift by
 * being written twice.
 */
final readonly class VisibilityCondition
{
    public function __construct(
        /** The form key being tested: a plain name, or `cf:` and a field key. */
        public string $field,
        public VisibilityOperator $operator,
        public ?string $value = null,
    ) {}

    /**
     * @param  mixed  $stored  The `visible_when` column, decoded.
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $field = trim((string) ($stored['field'] ?? ''));
        $operator = VisibilityOperator::tryFrom((string) ($stored['operator'] ?? ''));

        if ($field === '' || $operator === null) {
            return null;
        }

        return new self($field, $operator, self::text($stored['value'] ?? null));
    }

    /**
     * @return array{field: string, operator: string, value: string|null}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }

    /**
     * Whether the field this condition guards should be shown.
     *
     * @param  array<string, mixed>  $formValues  Keyed the way `field` names them.
     */
    public function matches(array $formValues): bool
    {
        $actual = $formValues[$this->field] ?? null;

        return $this->operator->matches($actual, $this->value);
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
