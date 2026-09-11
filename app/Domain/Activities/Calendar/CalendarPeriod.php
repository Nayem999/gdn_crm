<?php

namespace App\Domain\Activities\Calendar;

use App\Domain\Settings\DisplayTime;
use Illuminate\Support\Carbon;

/**
 * What a calendar screen is looking at: a scale, the day it is anchored on, and
 * the run of days that follow from those two.
 *
 * Every date here is on the **office clock** (see DisplayTime). The query
 * window — `from()` and `to()` — is the same span converted to what the column
 * stores, and that conversion is the whole reason this class exists: a month
 * that runs 1–31 locally does not run 1–31 in UTC, and querying it as though it
 * did drops the first evening and the last.
 */
final readonly class CalendarPeriod
{
    private function __construct(
        public CalendarScale $scale,
        /** Midnight on the anchor day, in the display timezone. */
        public Carbon $anchor,
        /** First day drawn, in the display timezone. */
        public Carbon $first,
        /** Last day drawn, in the display timezone. */
        public Carbon $last,
    ) {}

    /**
     * The period containing a given day.
     *
     * A month grid is padded out to whole weeks, because a month that started
     * on a Thursday would otherwise draw a ragged first row and the day-of-week
     * headings would stop lining up.
     */
    public static function for(CalendarScale $scale, Carbon $day): self
    {
        $anchor = DisplayTime::display($day)->startOfDay();
        $weekStart = DisplayTime::weekStartsOn();

        [$first, $last] = match ($scale) {
            CalendarScale::Month => [
                $anchor->copy()->startOfMonth()->startOfWeek($weekStart),
                $anchor->copy()->endOfMonth()->endOfWeek(self::weekEnd($weekStart)),
            ],
            CalendarScale::Week => [
                $anchor->copy()->startOfWeek($weekStart),
                $anchor->copy()->endOfWeek(self::weekEnd($weekStart)),
            ],
            CalendarScale::Day => [$anchor->copy(), $anchor->copy()],
        };

        return new self($scale, $anchor, $first->startOfDay(), $last->startOfDay());
    }

    /**
     * The start of the query window, in stored terms.
     */
    public function from(): Carbon
    {
        return DisplayTime::startOfDay($this->first);
    }

    /**
     * The end of the query window, in stored terms. Inclusive of the last
     * day's final second, which is what `scopeDueBetween` expects.
     */
    public function to(): Carbon
    {
        return DisplayTime::endOfDay($this->last);
    }

    /**
     * Every day drawn, in order, on the office clock.
     *
     * @return array<int, Carbon>
     */
    public function days(): array
    {
        $days = [];

        for ($day = $this->first->copy(); $day->lessThanOrEqualTo($this->last); $day->addDay()) {
            $days[] = $day->copy();
        }

        return $days;
    }

    /**
     * The days in rows of seven, for a month grid.
     *
     * @return array<int, array<int, Carbon>>
     */
    public function weeks(): array
    {
        return array_chunk($this->days(), 7);
    }

    /**
     * How the period is named in the header.
     */
    public function label(): string
    {
        return match ($this->scale) {
            CalendarScale::Month => $this->anchor->format('F Y'),
            CalendarScale::Day => DisplayTime::date($this->anchor),
            CalendarScale::Week => $this->first->isSameMonth($this->last)
                ? $this->first->format('j').'–'.$this->last->format('j M Y')
                : $this->first->format('j M').' – '.$this->last->format('j M Y'),
        };
    }

    /**
     * The same scale, one period earlier or later.
     *
     * Stepping a month moves from the **anchor**, not from the first day drawn:
     * the grid's first cell is often in the previous month, so stepping from it
     * would skip a month every time the padding ran long.
     */
    public function step(int $direction): self
    {
        $anchor = match ($this->scale) {
            CalendarScale::Month => $this->anchor->copy()->addMonthsNoOverflow($direction),
            CalendarScale::Week => $this->anchor->copy()->addWeeks($direction),
            CalendarScale::Day => $this->anchor->copy()->addDays($direction),
        };

        return self::for($this->scale, $anchor);
    }

    public function contains(Carbon $day): bool
    {
        return DisplayTime::display($day)->startOfDay()->betweenIncluded($this->first, $this->last);
    }

    /**
     * Whether a day belongs to the month being looked at, as opposed to the
     * padding either side of it. Always true off a month grid.
     */
    public function isInFocus(Carbon $day): bool
    {
        return $this->scale !== CalendarScale::Month
            || $day->isSameMonth($this->anchor);
    }

    /**
     * The weekday headings, in the order this grid draws them.
     *
     * @return array<int, string>
     */
    public function weekdayNames(): array
    {
        $names = [];

        foreach ($this->days() as $index => $day) {
            if ($index >= 7) {
                break;
            }

            $names[] = $day->format('D');
        }

        return $names;
    }

    /**
     * Carbon's `endOfWeek` takes the week's **last** day, so it has to be
     * derived from the configured first one rather than passed the same value.
     */
    private static function weekEnd(int $weekStart): int
    {
        return ($weekStart + 6) % 7;
    }
}
