<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;

/**
 * Marks an activity done.
 *
 * One of the three writers of `status`, which is deliberately out of
 * Activity::$fillable — the same arrangement that keeps MoveDealStageAction the
 * only writer of a deal's stage. A form that could set it would be a second
 * path to "done" and the two would eventually disagree about `completed_at`.
 */
class CompleteActivityAction
{
    /**
     * @return bool whether anything changed — false when it was already
     *              completed, so a board drop into the column it came from
     *              reports no move
     */
    public function __invoke(Activity $activity, ?string $notes = null): bool
    {
        if ($activity->isCompleted()) {
            return false;
        }

        $activity->forceFill([
            'status' => ActivityStatus::Completed->value,
            // Stamped once, when it was actually finished. Re-completing an
            // already completed activity is refused above rather than pushing
            // this forward, the way re-clicking a deal's closing stage is.
            'completed_at' => now(),
            'completion_notes' => $notes === null || trim($notes) === '' ? $activity->completion_notes : trim($notes),
        ])->save();

        return true;
    }
}
