<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\DTOs\ActivityData;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Activities\Scheduling\AvailabilityFinder;
use App\Domain\Activities\Scheduling\WorkingHours;
use App\Domain\Settings\DisplayTime;
use App\Models\User;
use RuntimeException;

/**
 * Books a meeting into somebody's diary.
 *
 * It is a thin layer over CreateActivityAction rather than a second way to
 * write an activity: a booked meeting is an ordinary meeting, and a second
 * writer would be a second place that could forget the owner, the notification
 * or the related record.
 *
 * What it adds is the slot check. The list a person clicked was a snapshot, and
 * the gap between drawing it and pressing the button is exactly when a
 * colleague books the same afternoon — so the span is re-tested here, inside
 * the action, where it cannot be skipped by a caller.
 */
class BookMeetingAction
{
    public function __construct(
        private readonly CreateActivityAction $createActivity,
        private readonly AvailabilityFinder $availability,
    ) {}

    /**
     * @param  string  $slot  A wall-clock "Y-m-d H:i" on the office clock, as
     *                        Slot::value() writes it.
     *
     * @throws RuntimeException when the slot has gone, or the working day does
     *                          not contain it
     */
    public function __invoke(
        string $slot,
        int $minutes,
        int $ownerId,
        string $subject,
        User $actor,
        ?string $relatedModule = null,
        ?int $relatedId = null,
        ?string $location = null,
        ?string $description = null,
        ?int $reminderMinutesBefore = null,
    ): Activity {
        $minutes = max(5, $minutes);
        $startsAt = DisplayTime::store($slot);
        $displayStart = DisplayTime::display($startsAt);

        if ($displayStart->isPast()) {
            throw new RuntimeException('That time has already passed.');
        }

        if (! WorkingHours::isWorkingDay($displayStart)) {
            throw new RuntimeException('That day is outside the working week.');
        }

        if (! $this->availability->isFree($ownerId, $startsAt, $minutes)) {
            throw new RuntimeException('That slot has just been taken. Pick another.');
        }

        return ($this->createActivity)(
            new ActivityData(
                subject: $subject,
                // The DTO parses this itself; handing it the stored value keeps
                // one conversion in one place.
                dueAt: $startsAt->format('Y-m-d H:i:s'),
                type: ActivityType::Meeting,
                description: $description,
                allDay: false,
                durationMinutes: $minutes,
                location: $location,
                reminderMinutesBefore: $reminderMinutesBefore,
                relatedModule: $relatedModule,
                relatedId: $relatedId,
                ownerId: $ownerId,
            ),
            $actor,
        );
    }
}
