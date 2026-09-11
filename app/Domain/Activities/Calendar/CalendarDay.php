<?php

namespace App\Domain\Activities\Calendar;

use App\Domain\Settings\DisplayTime;
use Illuminate\Support\Carbon;

/**
 * One cell of the grid: a day on the office clock and what sits on it.
 *
 * Every day in the period gets one of these, including the empty ones — the
 * view iterates days rather than events, so a month with three appointments
 * still draws thirty-odd cells.
 */
final readonly class CalendarDay
{
    /**
     * @param  array<int, CalendarEvent>  $events  Already ordered.
     */
    public function __construct(
        public Carbon $date,
        public array $events,
        public bool $isInFocus,
    ) {}

    public function key(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function isToday(): bool
    {
        return $this->date->isSameDay(DisplayTime::now());
    }

    public function isWeekend(): bool
    {
        return $this->date->isWeekend();
    }

    /**
     * @return array<int, CalendarEvent>
     */
    public function allDayEvents(): array
    {
        return array_values(array_filter($this->events, static fn (CalendarEvent $e): bool => $e->allDay));
    }

    /**
     * @return array<int, CalendarEvent>
     */
    public function timedEvents(): array
    {
        return array_values(array_filter($this->events, static fn (CalendarEvent $e): bool => ! $e->allDay));
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }
}
