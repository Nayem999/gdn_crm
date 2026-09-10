<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;

/**
 * Calls off an activity that is not going to happen.
 *
 * Kept apart from deleting it: a cancelled meeting is a fact worth having on
 * the record, and a removed one leaves the customer's history with a gap.
 */
class CancelActivityAction
{
    public function __invoke(Activity $activity, ?string $notes = null): bool
    {
        if ($activity->isCancelled()) {
            return false;
        }

        $activity->forceFill([
            'status' => ActivityStatus::Cancelled->value,
            // Not completed_at: this did not happen, and a cancelled activity
            // counting towards "completed this week" would overstate the work.
            'completed_at' => null,
            'completion_notes' => $notes === null || trim($notes) === '' ? $activity->completion_notes : trim($notes),
        ])->save();

        return true;
    }
}
