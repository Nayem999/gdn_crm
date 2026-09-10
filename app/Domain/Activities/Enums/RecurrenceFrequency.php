<?php

namespace App\Domain\Activities\Enums;

use Illuminate\Support\Carbon;

/**
 * How often a repeating activity comes round.
 *
 * Four plain frequencies rather than an RRULE. "The second Tuesday of every
 * month" is a real thing people ask for, and it is also a parser, a UI and a
 * pile of corner cases — if it is wanted it deserves a task of its own rather
 * than being smuggled in behind a string column.
 */
enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * How the gap between occurrences reads on screen.
     */
    public function intervalLabel(int $interval): string
    {
        $unit = match ($this) {
            self::Daily => 'day',
            self::Weekly => 'week',
            self::Monthly => 'month',
            self::Yearly => 'year',
        };

        return $interval <= 1
            ? 'Every '.$unit
            : 'Every '.$interval.' '.$unit.'s';
    }

    /**
     * The moment `$steps` intervals after `$from`.
     *
     * Counted from the series start rather than from the previous occurrence,
     * and with the NoOverflow variants. Both matter: `addMonth()` on 31 January
     * lands on 3 March, so a monthly series stepped one at a time walks off the
     * end of the month it started in and never comes back.
     */
    public function advance(Carbon $from, int $steps, int $interval = 1): Carbon
    {
        $moved = $steps * max(1, $interval);

        return match ($this) {
            self::Daily => $from->copy()->addDays($moved),
            self::Weekly => $from->copy()->addWeeks($moved),
            self::Monthly => $from->copy()->addMonthsNoOverflow($moved),
            self::Yearly => $from->copy()->addYearsNoOverflow($moved),
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
