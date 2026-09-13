<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Enums\ScheduleFrequency;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\Models\ReportSchedule;
use App\Domain\Shared\Enums\ExportFormat;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Sets a report to post itself.
 *
 * `next_run_at` is computed here and nowhere a form can reach, because a
 * schedule that could be given a due time in the past would fire on the next
 * sweep and every one after it.
 */
class SaveReportScheduleAction
{
    /**
     * @param  array<int, string>  $recipients
     *
     * @throws RuntimeException when there is nobody to send it to
     */
    public function __invoke(
        ReportSchedule $schedule,
        Report $report,
        User $owner,
        ScheduleFrequency $frequency,
        ExportFormat $format,
        array $recipients,
        int $hour = 8,
        ?int $dayOfWeek = null,
        ?int $dayOfMonth = null,
        bool $active = true,
        ?Carbon $now = null,
    ): ReportSchedule {
        $schedule->forceFill([
            'report_id' => $report->id,
            // The owner is whose view it runs under, and it is set once: a
            // schedule that changed hands would quietly change its figures.
            'user_id' => $schedule->exists ? $schedule->user_id : $owner->id,
            'frequency' => $frequency->value,
            'format' => $format->value,
            'recipients' => array_values($recipients),
            'hour' => max(0, min(23, $hour)),
            'day_of_week' => $frequency->usesDayOfWeek() ? max(0, min(6, $dayOfWeek ?? 1)) : null,
            'day_of_month' => $frequency->usesDayOfMonth() ? max(1, min(31, $dayOfMonth ?? 1)) : null,
            'is_active' => $active,
        ]);

        if ($schedule->addresses() === []) {
            throw new RuntimeException('A schedule needs at least one address to send to.');
        }

        // Always from now, never from the old due time: changing a schedule is
        // not a reason to send the one it missed.
        $schedule->next_run_at = $schedule->nextRunAfter($now ?? now());

        $schedule->save();

        return $schedule->refresh();
    }
}
