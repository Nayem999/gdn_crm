<?php

namespace App\Jobs;

use App\Domain\Reports\Models\ReportSchedule;
use App\Mail\ScheduledReportMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Renders one scheduled report and posts it.
 *
 * Queued, because producing a PDF of a thousand-row report is not something to
 * do inside a scheduler tick that has other schedules waiting behind it.
 *
 * The outcome is written back onto the schedule either way, so a report that
 * has been failing silently for a fortnight is visible on the screen rather
 * than only in the logs.
 */
class SendScheduledReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $scheduleId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $schedule = ReportSchedule::query()->with(['report', 'owner'])->find($this->scheduleId);

        if ($schedule === null || ! $schedule->is_active) {
            return;
        }

        $addresses = $schedule->addresses();

        if ($addresses === [] || $schedule->report === null || $schedule->owner === null) {
            $schedule->forceFill([
                'last_status' => 'skipped',
                'last_error' => 'Nothing to send it to, or the report has gone.',
            ])->save();

            return;
        }

        try {
            Mail::to($addresses)->send(new ScheduledReportMail($schedule));

            $schedule->forceFill(['last_status' => 'sent', 'last_error' => null])->save();
        } catch (Throwable $exception) {
            // Truncated: a mail provider can echo the whole payload back inside
            // an error message — the same reasoning as the notification log.
            $schedule->forceFill([
                'last_status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReportSchedule::query()->whereKey($this->scheduleId)->update([
            'last_status' => 'failed',
            'last_error' => mb_substr($exception?->getMessage() ?? 'Delivery failed.', 0, 500),
        ]);
    }
}
