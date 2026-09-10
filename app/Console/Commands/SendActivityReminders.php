<?php

namespace App\Console\Commands;

use App\Domain\Activities\Actions\SendActivityRemindersAction;
use Illuminate\Console\Command;

/**
 * Sends the activity reminders that have come due.
 *
 * Scheduled every minute. The work is one indexed query when there is nothing
 * to send, and each reminder it does find is handed to the notification engine,
 * which queues the delivery — nothing is sent inside this process.
 */
class SendActivityReminders extends Command
{
    protected $signature = 'activities:send-reminders';

    protected $description = 'Queue reminders for activities whose lead time has arrived';

    public function handle(SendActivityRemindersAction $send): int
    {
        $sent = $send();

        $this->info($sent.' '.str('reminder')->plural($sent).' queued.');

        return self::SUCCESS;
    }
}
