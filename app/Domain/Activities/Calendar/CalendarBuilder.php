<?php

namespace App\Domain\Activities\Calendar;

use App\Domain\Activities\Models\Activity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fills a period's days with the activities that fall on them.
 *
 * It is handed a query rather than building one, for the same reason the data
 * view kit is: the caller owns its own visibility scope and filters, and a
 * builder that assembled its own query would be a second place where "what may
 * this person see" is decided.
 */
class CalendarBuilder
{
    /**
     * The most events one period will draw.
     *
     * A calendar has no pager, so the query needs some ceiling or a busy team's
     * month view becomes an unbounded read — which the architecture rules
     * forbid on any business table. The screen says when it has hit it rather
     * than quietly drawing a partial month.
     */
    public const MAX_EVENTS = 500;

    /**
     * @param  Builder<Activity>  $query  Already scoped to what the viewer may see.
     */
    public function build(Builder $query, CalendarPeriod $period): CalendarGrid
    {
        $activities = $query
            ->dueBetween($period->from(), $period->to())
            ->with(['owner', 'related'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(self::MAX_EVENTS + 1)
            ->get();

        $truncated = $activities->count() > self::MAX_EVENTS;

        $byDay = [];

        foreach ($activities->take(self::MAX_EVENTS) as $activity) {
            $event = CalendarEvent::for($activity);
            $byDay[$event->dayKey()][] = $event;
        }

        foreach ($byDay as $key => $events) {
            usort($events, static fn (CalendarEvent $a, CalendarEvent $b): int => strcmp($a->sortKey(), $b->sortKey()));
            $byDay[$key] = $events;
        }

        $days = [];

        foreach ($period->days() as $day) {
            $key = $day->format('Y-m-d');

            $days[$key] = new CalendarDay(
                date: $day,
                events: $byDay[$key] ?? [],
                isInFocus: $period->isInFocus($day),
            );
        }

        return new CalendarGrid($period, $days, $truncated);
    }
}
