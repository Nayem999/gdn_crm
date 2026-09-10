<?php

namespace App\Domain\Activities\Actions;

use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;

class DeleteActivityAction
{
    /**
     * Removes an activity, and a series with it.
     *
     * The foreign key cascades, but only on a hard delete — a soft-deleted
     * master would otherwise leave its occurrences on everybody's calendar with
     * nothing to explain them. Occurrences already completed are left where
     * they are: they happened, and the record of that is not the series.
     */
    public function __invoke(Activity $activity): void
    {
        if (! $activity->isOccurrence()) {
            $activity->occurrences()
                ->where('status', ActivityStatus::Open->value)
                ->get()
                ->each(fn (Activity $occurrence) => $occurrence->delete());
        }

        $activity->delete();
    }
}
