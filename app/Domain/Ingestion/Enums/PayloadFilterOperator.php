<?php

namespace App\Domain\Ingestion\Enums;

use App\Domain\Ingestion\PayloadReader;

/**
 * How a filter decides whether a delivery is one this source cares about.
 *
 * Named for the payload, not shortened to FilterOperator: the data-view kit
 * already has a FilterOperator for querying our own tables, and two enums of
 * that name in one application is how the wrong one gets imported.
 *
 * Deliberately small. A source that has to express something this cannot is a
 * source whose sender should stop posting that event, and a rule language grown
 * one operator at a time ends up being a language nobody documented.
 */
enum PayloadFilterOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case Exists = 'exists';
    case NotExists = 'not_exists';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'is',
            self::NotEquals => 'is not',
            self::Contains => 'contains',
            self::Exists => 'is present',
            self::NotExists => 'is not present',
        };
    }

    /**
     * Whether this operator needs something to compare against.
     */
    public function needsValue(): bool
    {
        return $this !== self::Exists && $this !== self::NotExists;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function matches(array $payload, string $path, ?string $expected): bool
    {
        $present = PayloadReader::has($payload, $path);

        if ($this === self::Exists) {
            return $present;
        }

        if ($this === self::NotExists) {
            return ! $present;
        }

        $actual = PayloadReader::value($payload, $path);
        // Compared as text, because a payload says 1 where a form says "1" and
        // nobody configuring this is thinking about JSON types.
        $actual = $actual === null ? null : (is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual);
        $expected = $expected === null ? '' : $expected;

        // Plain returns rather than a match: the two presence operators are
        // already answered above, so a match listing them again is a match with
        // arms that cannot be reached.
        if ($this === self::Equals) {
            return $actual !== null && strcasecmp($actual, $expected) === 0;
        }

        if ($this === self::NotEquals) {
            // A missing value "is not" anything, which is what somebody
            // filtering out one event type expects.
            return $actual === null || strcasecmp($actual, $expected) !== 0;
        }

        return $actual !== null && $expected !== '' && str_contains(strtolower($actual), strtolower($expected));
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
