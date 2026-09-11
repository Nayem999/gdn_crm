<?php

namespace App\Domain\Settings;

use App\Domain\Company\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The installation's clock: which timezone people read and write times in, and
 * how a date is written down.
 *
 * Every datetime in this database is stored in UTC, because `app.timezone` is
 * UTC and Eloquent casts against it. What the company profile carries is a
 * **display** timezone — the wall clock the office keeps. The two were never
 * connected until the calendar needed them to be: a task stored at 23:30 UTC
 * belongs to the *next* day in Dhaka, and a month grid that ignores that draws
 * it in the wrong cell.
 *
 * So this is the one place that converts. Reading a stored time for a person
 * goes through `display()`; turning what a person typed into something to store
 * goes through `store()`. Nothing else should call `setTimezone`.
 *
 * The date and time formats come from the localisation settings group, which
 * has offered them since 1.8 with nothing reading them.
 */
final class DisplayTime
{
    /**
     * Carbon's day-of-week constants, by the token the setting stores.
     */
    private const WEEK_START = [
        'monday' => CarbonInterface::MONDAY,
        'sunday' => CarbonInterface::SUNDAY,
        'saturday' => CarbonInterface::SATURDAY,
    ];

    /**
     * The company's wall clock.
     *
     * Falls back to the application timezone rather than to a guess: a profile
     * that has never been saved is not a profile that means "Africa/Abidjan".
     */
    public static function timezone(): string
    {
        $timezone = Company::current()->timezone;

        return $timezone === '' ? (string) config('app.timezone', 'UTC') : $timezone;
    }

    /**
     * A stored moment, as the office reads it.
     */
    public static function display(CarbonInterface $moment): Carbon
    {
        return Carbon::parse($moment)->setTimezone(self::timezone());
    }

    /**
     * A moment the office typed, as it is stored.
     *
     * The string is interpreted **in the company timezone** — "10:30 on the
     * 15th" means half past ten here, not half past ten UTC — and comes back in
     * the application timezone, which is what the column holds.
     */
    public static function store(string $wallClock): Carbon
    {
        return Carbon::parse($wallClock, self::timezone())
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * Now, on the office clock. Respects Carbon::setTestNow, which is what
     * makes a "what does today look like" test possible at all.
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /**
     * Midnight at the start of a displayed day, stored.
     *
     * A calendar window is chosen in display terms ("the 1st to the 31st") and
     * queried in stored terms, and this is the join between the two.
     */
    public static function startOfDay(CarbonInterface $day): Carbon
    {
        return self::display($day)->startOfDay()
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public static function endOfDay(CarbonInterface $day): Carbon
    {
        return self::display($day)->endOfDay()
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public static function dateFormat(): string
    {
        return (string) settings('localisation.date_format', 'j M Y');
    }

    public static function timeFormat(): string
    {
        return (string) settings('localisation.time_format', 'H:i');
    }

    public static function date(CarbonInterface $moment): string
    {
        return self::display($moment)->format(self::dateFormat());
    }

    public static function time(CarbonInterface $moment): string
    {
        return self::display($moment)->format(self::timeFormat());
    }

    public static function dateTime(CarbonInterface $moment): string
    {
        return self::date($moment).', '.self::time($moment);
    }

    /**
     * Which day a week grid starts on, as a Carbon constant.
     */
    public static function weekStartsOn(): int
    {
        $token = (string) settings('localisation.week_starts_on', 'monday');

        return self::WEEK_START[$token] ?? CarbonInterface::MONDAY;
    }
}
