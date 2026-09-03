<?php

namespace App\Jobs;

use App\Domain\Leads\Actions\ScoreLeadsAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rescores the whole lead database after the scoring rules change.
 *
 * Queued because it touches every lead, and nobody saving a rule should wait
 * for it. The work is a handful of queries per rule regardless of table size,
 * so it does not need chunking across jobs.
 */
class RescoreLeads implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(ScoreLeadsAction $scoreLeads): void
    {
        $scoreLeads();
    }
}
