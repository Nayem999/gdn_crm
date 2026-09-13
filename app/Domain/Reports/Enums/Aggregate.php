<?php

namespace App\Domain\Reports\Enums;

/**
 * What a measure does to the rows in a group.
 *
 * A closed set, because the value reaches SQL as a function name. Nothing from
 * a request picks the function directly — a report names a measure key, and the
 * measure names one of these.
 */
enum Aggregate: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Average = 'avg';
    case Min = 'min';
    case Max = 'max';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Count',
            self::Sum => 'Total',
            self::Average => 'Average',
            self::Min => 'Lowest',
            self::Max => 'Highest',
        };
    }

    /**
     * The SQL function. Written out rather than derived from the case value so
     * that renaming a case cannot silently change the query.
     */
    public function sqlFunction(): string
    {
        return match ($this) {
            self::Count => 'COUNT',
            self::Sum => 'SUM',
            self::Average => 'AVG',
            self::Min => 'MIN',
            self::Max => 'MAX',
        };
    }

    /**
     * Whether an empty group should read as nought rather than as nothing.
     *
     * A count of no rows is nought. An average of no rows is not — it is
     * unknown, and printing nought would be a claim nobody made.
     */
    public function zeroIsMeaningful(): bool
    {
        return $this === self::Count || $this === self::Sum;
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
