<?php

namespace App\Domain\CustomFields\Enums;

/**
 * How a visibility condition compares.
 *
 * A small, deliberately blunt set. A form condition answers "is this the case
 * right now", against a value the person is still typing — so there is no
 * "greater than" here, which would flicker a field in and out as somebody types
 * a number, and no date arithmetic, which belongs in Phase 5's workflow engine
 * where it is evaluated once against a saved record.
 */
enum VisibilityOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';
    case IsTrue = 'is_true';
    case IsFalse = 'is_false';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'is',
            self::NotEquals => 'is not',
            self::Contains => 'contains',
            self::IsEmpty => 'is empty',
            self::IsNotEmpty => 'is not empty',
            self::IsTrue => 'is ticked',
            self::IsFalse => 'is not ticked',
        };
    }

    /**
     * Whether this operator needs a value to compare against.
     */
    public function needsValue(): bool
    {
        return match ($this) {
            self::Equals, self::NotEquals, self::Contains => true,
            default => false,
        };
    }

    /**
     * The comparison itself. Mirrored in Alpine by `resources/js/custom-field-visibility.js`;
     * the two must agree, so keep any change here in step with that file.
     */
    public function matches(mixed $actual, ?string $expected): bool
    {
        $empty = $actual === null
            || $actual === ''
            || $actual === []
            || $actual === false;

        return match ($this) {
            self::IsEmpty => $empty,
            self::IsNotEmpty => ! $empty,
            // A tickbox reads the value itself, not its emptiness: "0" and ""
            // are both unticked, and anything else is ticked.
            self::IsTrue => self::truthy($actual),
            self::IsFalse => ! self::truthy($actual),
            self::Equals => self::compare($actual, $expected),
            self::NotEquals => ! self::compare($actual, $expected),
            self::Contains => $expected !== null
                && str_contains(self::asText($actual), strtolower($expected)),
        };
    }

    /**
     * Compared as text, and case-insensitively.
     *
     * A form value arrives as a string whatever its type — a number input gives
     * "30", a select gives its key — so a strict comparison against a stored
     * condition value would fail on the same answer typed a different way.
     * A multi-value answer matches when any of its entries does, which is what
     * "is" means for a multi-select.
     */
    private static function compare(mixed $actual, ?string $expected): bool
    {
        if ($expected === null) {
            return false;
        }

        $wanted = strtolower(trim($expected));

        if (is_array($actual)) {
            foreach ($actual as $one) {
                if (strtolower(trim((string) $one)) === $wanted) {
                    return true;
                }
            }

            return false;
        }

        return self::asText($actual) === $wanted;
    }

    private static function asText(mixed $value): string
    {
        if (is_array($value)) {
            return strtolower(implode(',', array_map('strval', $value)));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return strtolower(trim((string) $value));
    }

    private static function truthy(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return ! in_array(self::asText($value), ['', '0', 'false', 'no'], true);
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
