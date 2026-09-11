<?php

namespace App\Domain\Activities\Calendar;

/**
 * A period with its days filled in — what the view actually renders.
 */
final readonly class CalendarGrid
{
    /**
     * @param  array<string, CalendarDay>  $days  Keyed by Y-m-d on the office clock.
     */
    public function __construct(
        public CalendarPeriod $period,
        public array $days,
        /** Whether the period holds more than CalendarBuilder::MAX_EVENTS. */
        public bool $truncated,
    ) {}

    public function day(string $key): ?CalendarDay
    {
        return $this->days[$key] ?? null;
    }

    /**
     * The days in rows of seven, for a month grid.
     *
     * @return array<int, array<int, CalendarDay>>
     */
    public function weeks(): array
    {
        return array_chunk(array_values($this->days), 7);
    }

    /**
     * @return array<int, CalendarDay>
     */
    public function list(): array
    {
        return array_values($this->days);
    }

    /**
     * @return array<int, CalendarEvent>
     */
    public function events(): array
    {
        $events = [];

        foreach ($this->days as $day) {
            foreach ($day->events as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function count(): int
    {
        return count($this->events());
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
