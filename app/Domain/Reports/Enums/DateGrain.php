<?php

namespace App\Domain\Reports\Enums;

/**
 * How finely a date dimension is grouped.
 *
 * Weeks start on Monday, because a sales week does and a report that started it
 * on Sunday would disagree with every other figure the company quotes.
 */
enum DateGrain: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
            self::Quarter => 'Quarter',
            self::Year => 'Year',
        };
    }

    /**
     * The expression that buckets a date column, with the column already
     * quoted by the caller.
     *
     * DATE_FORMAT rather than YEAR()/MONTH() pairs: one sortable string per
     * bucket means the ORDER BY is the GROUP BY, and a report cannot end up
     * with December before February.
     */
    public function expression(string $column): string
    {
        return match ($this) {
            self::Day => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            // %x-W%v is the ISO week-numbering year with the ISO week, so the
            // last days of December fall in the first week of the next year
            // exactly as the standard says — %Y-%V would pair the wrong year
            // with the week and put one bucket a year out.
            self::Week => "DATE_FORMAT({$column}, '%x-W%v')",
            self::Month => "DATE_FORMAT({$column}, '%Y-%m')",
            self::Quarter => "CONCAT(YEAR({$column}), '-Q', QUARTER({$column}))",
            self::Year => "DATE_FORMAT({$column}, '%Y')",
        };
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
