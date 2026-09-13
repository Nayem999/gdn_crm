<?php

namespace App\Console\Commands;

use App\Domain\Reports\Actions\DispatchScheduledReportsAction;
use Illuminate\Console\Command;

/**
 * Queues the scheduled reports that have come due.
 *
 * Hourly, because a schedule is set to an hour and not to a minute. The work is
 * one indexed range scan when nothing is due.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Queue the scheduled reports that have come due';

    public function handle(DispatchScheduledReportsAction $dispatch): int
    {
        $queued = $dispatch();

        $this->info($queued.' '.str('report')->plural($queued).' queued.');

        return self::SUCCESS;
    }
}
