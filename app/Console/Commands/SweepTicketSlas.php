<?php

namespace App\Console\Commands;

use App\Domain\Support\Actions\SweepSlaAction;
use Illuminate\Console\Command;

/**
 * Warns about and records the SLA promises that have come due.
 *
 * Scheduled every minute. The work is one indexed query when nothing is close,
 * and each warning or breach it does find is handed to the notification engine,
 * which queues the delivery — nothing is sent inside this process.
 */
class SweepTicketSlas extends Command
{
    protected $signature = 'support:sweep-sla';

    protected $description = 'Warn on and record ticket SLA breaches that have come due';

    public function handle(SweepSlaAction $sweep): int
    {
        $result = $sweep();

        $this->info($result['warned'].' warned, '.$result['breached'].' breached.');

        return self::SUCCESS;
    }
}
