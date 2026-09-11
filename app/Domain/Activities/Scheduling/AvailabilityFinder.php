<?php

namespace App\Domain\Activities\Scheduling;

use App\Domain\Activities\Calendar\CalendarEvent;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use App\Domain\Settings\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When somebody is free.
 *
 * Slots are cut from the working day at the configured interval, and each one
 * is checked against what that person already has booked. A slot is offered
 * only if the **whole meeting** fits inside it — a 60-minute meeting on 30-
 * minute slots needs the next slot free as well, which is the bug that makes
 * naive slot pickers double-book.
 */
class AvailabilityFinder
{
    /**
     * Every slot on a day, free and taken alike.
     *
     * Taken ones come back too, marked: a screen that silently omits them tells
     * somebody their colleague has no afternoon without saying why.
     *
     * @return array<int, Slot>
     */
    public function slotsFor(int $ownerId, CarbonInterface $day, ?int $minutes = null): array
    {
        if (! WorkingHours::isWorkingDay($day)) {
            return [];
        }

        $minutes = max(5, $minutes ?? WorkingHours::defaultMeetingMinutes());
        $opens = WorkingHours::opensOn($day);
        $closes = WorkingHours::closesOn($day);

        if ($closes->lessThanOrEqualTo($opens)) {
            return [];
        }

        $busy = $this->busyOn($ownerId, $day);
        $step = WorkingHours::slotMinutes();
        $slots = [];

        for ($start = $opens->copy(); $start->copy()->addMinutes($minutes)->lessThanOrEqualTo($closes); $start->addMinutes($step)) {
            $end = $start->copy()->addMinutes($minutes);

            $slots[] = new Slot(
                startsAt: $start->copy(),
                endsAt: $end,
                conflict: $this->firstOverlap($busy, $start, $end),
            );
        }

        return $slots;
    }

    /**
     * Whether a specific span is bookable for somebody.
     *
     * The screen asks this again at save time. A slot list is a snapshot, and
     * two people looking at the same afternoon is exactly when a booking
     * collides.
     */
    public function isFree(int $ownerId, Carbon $startsAt, int $minutes, ?int $ignoreActivityId = null): bool
    {
        $end = $startsAt->copy()->addMinutes(max(5, $minutes));

        $busy = array_filter(
            $this->busyOn($ownerId, $startsAt),
            static fn (Activity $activity): bool => $activity->id !== $ignoreActivityId,
        );

        return $this->firstOverlap($busy, $startsAt, $end) === null;
    }

    /**
     * What already occupies that person's day.
     *
     * The window is the **displayed** day converted to stored terms, so an
     * evening appointment is not missed by a query that assumed UTC midnight.
     *
     * All-day entries do not block: "invoice run" sitting on Thursday is not a
     * reason nobody may meet on Thursday. Cancelled ones do not block either —
     * a called-off meeting has given its slot back.
     *
     * @return array<int, Activity>
     */
    private function busyOn(int $ownerId, CarbonInterface $day): array
    {
        return Activity::query()
            ->where('owner_id', $ownerId)
            ->where('all_day', false)
            ->where('status', '!=', ActivityStatus::Cancelled->value)
            ->dueBetween(
                // A meeting that started the previous evening can still be
                // running this morning, so the read starts a day early and the
                // overlap test does the rest.
                DisplayTime::startOfDay($day)->subDay(),
                DisplayTime::endOfDay($day),
            )
            ->get()
            ->all();
    }

    /**
     * The first thing overlapping a span, or null.
     *
     * Half-open on both sides: a meeting ending at 10:00 does not clash with
     * one starting at 10:00, which is the whole point of back-to-back slots.
     *
     * @param  array<int, Activity>  $busy
     */
    private function firstOverlap(array $busy, Carbon $start, Carbon $end): ?Activity
    {
        foreach ($busy as $activity) {
            $busyStart = DisplayTime::display($activity->due_at);
            $busyEnd = $busyStart->copy()->addMinutes(
                $activity->duration_minutes ?? CalendarEvent::DEFAULT_MINUTES
            );

            if ($busyStart->lessThan($end) && $busyEnd->greaterThan($start)) {
                return $activity;
            }
        }

        return null;
    }
}
