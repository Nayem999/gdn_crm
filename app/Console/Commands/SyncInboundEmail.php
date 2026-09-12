<?php

namespace App\Console\Commands;

use App\Domain\Mail\Actions\SyncInboundEmailAction;
use App\Domain\Mail\Inbound\InboundMailbox;
use App\Domain\Mail\Inbound\InboundMailConfiguration;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read the monitored mailbox.
 *
 * Scheduled, and safe to run twice: the Message-ID index makes a second pass
 * over the same messages a no-op, which matters because the schedule and an
 * administrator pressing the button can easily overlap.
 */
class SyncInboundEmail extends Command
{
    protected $signature = 'mail:sync-inbound {--limit=100 : How many messages to read in one pass}';

    protected $description = 'Read new messages from the configured inbound mailbox';

    public function handle(InboundMailConfiguration $configuration, SyncInboundEmailAction $sync): int
    {
        if (! $configuration->isEnabled()) {
            $this->info('Inbound email is switched off.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->handle(
                app(InboundMailbox::class),
                $configuration->folder(),
                (int) $this->option('limit'),
            );
        } catch (Throwable $failure) {
            // Reported rather than thrown: this runs on a schedule, and a stack
            // trace every minute in the log is how people stop reading the log.
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Read %d message(s), stored %d.', $result['read'], $result['stored']));

        return self::SUCCESS;
    }
}
