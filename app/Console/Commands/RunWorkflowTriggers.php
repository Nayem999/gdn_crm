<?php

namespace App\Console\Commands;

use App\Domain\Approvals\Actions\EscalateApprovalsAction;
use App\Domain\Workflows\Actions\RunDateWorkflowsAction;
use App\Domain\Workflows\Actions\RunScheduledWorkflowsAction;
use Illuminate\Console\Command;

/**
 * Everything the clock brings around: the two triggers no record event can
 * raise, and approvals nobody answered in time.
 *
 * One command because all three are the same shape of work — a sweep for
 * occasions time has produced — and all three must run every minute. Separate
 * commands would be separate schedule entries to keep in step, and separate
 * chances for one to be missing from a deployment.
 */
class RunWorkflowTriggers extends Command
{
    protected $signature = 'workflows:run-triggers';

    protected $description = 'Fire the date-based and scheduled workflows that have come due, and escalate approvals nobody answered';

    public function handle(
        RunDateWorkflowsAction $dates,
        RunScheduledWorkflowsAction $scheduled,
        EscalateApprovalsAction $escalate,
    ): int {
        $started = $dates() + $scheduled();

        // Here rather than in a command of its own: it is the same shape of
        // work — a sweep for things the clock has brought around — and it must
        // run on the same minute cadence. Two entries would be two chances for
        // one of them to be missing from a deployment.
        $escalated = $escalate();

        $this->info($started.' workflow '.str('run')->plural($started).' started, '
            .$escalated.' '.str('approval')->plural($escalated).' escalated.');

        return self::SUCCESS;
    }
}
