<?php

namespace App\Domain\Reports\Enums;

use Illuminate\Support\Carbon;

/**
 * Which stretch of time a report covers.
 *
 * Stored as a **relative** period rather than two dates, so a saved "last month"
 * report — and above all a scheduled one — covers last month whenever it runs,
 * instead of the month somebody happened to save it in. Only Custom carries its
 * own dates.
 *
 * Boundaries are worked out on the office's clock: "this month" is this month in
 * the company timezone, not in UTC, or a Dhaka office would see the 1st's
 * morning counted in the previous month.
 */
enum DatePeriod: string
{
    case AllTime = 'all_time';
    case Today = 'today';
    case ThisWeek = 'this_week';
    case LastWeek = 'last_week';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case LastQuarter = 'last_quarter';
    case ThisYear = 'this_year';
    case LastYear = 'last_year';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::AllTime => 'All time',
            self::Today => 'Today',
            self::ThisWeek => 'This week',
            self::LastWeek => 'Last week',
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
            self::ThisQuarter => 'This quarter',
            self::LastQuarter => 'Last quarter',
            self::ThisYear => 'This year',
            self::LastYear => 'Last year',
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::Last90Days => 'Last 90 days',
            self::Custom => 'Custom range',
        };
    }

    /**
     * The first and last day covered, inclusive, on the office's calendar — or
     * null for no restriction.
     *
     * A custom range with neither end given is no restriction; one with a
     * single end is open on the other side.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}|null
     */
    public function days(Carbon $today, ?string $from = null, ?string $to = null): ?array
    {
        $today = $today->copy()->startOfDay();

        return match ($this) {
            self::AllTime => null,
            self::Today => [$today, $today->copy()],
            // Weeks start on Monday, as DateGrain's do.
            self::ThisWeek => [$today->copy()->startOfWeek(Carbon::MONDAY), $today->copy()->endOfWeek(Carbon::SUNDAY)],
            self::LastWeek => [
                $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY),
                $today->copy()->subWeek()->endOfWeek(Carbon::SUNDAY),
            ],
            self::ThisMonth => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            self::LastMonth => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            self::ThisQuarter => [$today->copy()->startOfQuarter(), $today->copy()->endOfQuarter()],
            self::LastQuarter => [
                $today->copy()->subQuarterNoOverflow()->startOfQuarter(),
                $today->copy()->subQuarterNoOverflow()->endOfQuarter(),
            ],
            self::ThisYear => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            self::LastYear => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
            // Inclusive of today: "the last 7 days" read on a Friday includes it.
            self::Last7Days => [$today->copy()->subDays(6), $today->copy()],
            self::Last30Days => [$today->copy()->subDays(29), $today->copy()],
            self::Last90Days => [$today->copy()->subDays(89), $today->copy()],
            self::Custom => self::custom($today, $from, $to),
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

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}|null
     */
    private static function custom(Carbon $today, ?string $from, ?string $to): ?array
    {
        $start = self::parse($from, $today);
        $end = self::parse($to, $today);

        if ($start === null && $end === null) {
            return null;
        }

        // Typed the wrong way round: meant as the same range, not as nothing.
        if ($start !== null && $end !== null && $start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    private static function parse(?string $date, Carbon $today): ?Carbon
    {
        if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return null;
        }

        // Refused rather than rolled over: 31 February is a typo, not 3 March.
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return Carbon::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, $today->getTimezone());
    }
}
