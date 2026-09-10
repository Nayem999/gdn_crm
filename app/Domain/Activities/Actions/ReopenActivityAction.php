<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;

/**
 * Puts a completed or cancelled activity back on somebody's list.
 */
class ReopenActivityAction
{
    public function __invoke(Activity $activity): bool
    {
        if ($activity->isOpen()) {
            return false;
        }

        $activity->forceFill([
            'status' => ActivityStatus::Open->value,
            'completed_at' => null,
            // Cleared with the stamp: an open task that still reads "done, left
            // a voicemail" describes something that is no longer true, the same
            // reason reopening a deal clears its close reason.
            'completion_notes' => null,
            // A reminder that already went out for the old due date should not
            // silence the next one.
            'reminder_sent_at' => null,
        ])->save();

        return true;
    }
}
