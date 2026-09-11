<?php

namespace App\Domain\Activities\Calendar;

/**
 * How much of the calendar is on screen.
 *
 * The scale reaches the component from the URL, so it is an enum rather than a
 * string: a value that is not one of these three resolves to the default rather
 * than deciding how far a date is stepped.
 */
enum CalendarScale: string
{
    case Month = 'month';
    case Week = 'week';
    case Day = 'day';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Month',
            self::Week => 'Week',
            self::Day => 'Day',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Month => 'calendar-days',
            self::Week => 'calendar-range',
            self::Day => 'calendar',
        };
    }

    /**
     * Whether this scale draws an hour grid. A month cell lists what is on that
     * day; a week and a day place each one against a time.
     */
    public function hasTimeGrid(): bool
    {
        return $this !== self::Month;
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
