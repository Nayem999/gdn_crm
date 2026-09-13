<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Models\ReportSchedule;
use App\Jobs\SendScheduledReport;
use Illuminate\Support\Carbon;

/**
 * Finds the schedules that have come due and queues them.
 *
 * `next_run_at` is advanced **before** the job is queued, not after it
 * succeeds. That is deliberate: a sweep that advanced afterwards would queue
 * the same report again on the next tick while the first was still rendering,
 * and a failing schedule would send a stream of duplicates rather than one
 * failure somebody can see.
 *
 * Advancing from *now* rather than from the old due time also makes a missed
 * window self-healing: a scheduler that was down for a day sends once and moves
 * on, instead of sending yesterday's twenty-four times.
 */
class DispatchScheduledReportsAction
{
    /**
     * @return int how many were queued
     */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= now();
        $queued = 0;

        ReportSchedule::query()
            ->due($now)
            ->orderBy('next_run_at')
            ->chunkById(100, function ($schedules) use (&$queued, $now) {
                foreach ($schedules as $schedule) {
                    $schedule->forceFill([
                        'last_run_at' => $now,
                        'next_run_at' => $schedule->nextRunAfter($now),
                    ])->save();

                    SendScheduledReport::dispatch($schedule->id);

                    $queued++;
                }
            });

        return $queued;
    }
}
