<?php

namespace App\Console\Commands;

use App\Domain\Workflows\Actions\RunDateWorkflowsAction;
use App\Domain\Workflows\Actions\RunScheduledWorkflowsAction;
use Illuminate\Console\Command;

/**
 * The two triggers no record event can raise.
 *
 * Both in one command because both are the same shape of work — a sweep for
 * occasions the clock has brought around — and both must run every minute. Two
 * commands would be two schedule entries to keep in step and two chances for
 * one of them to be missing from a deployment.
 */
class RunWorkflowTriggers extends Command
{
    protected $signature = 'workflows:run-triggers';

    protected $description = 'Fire the date-based and scheduled workflows that have come due';

    public function handle(RunDateWorkflowsAction $dates, RunScheduledWorkflowsAction $scheduled): int
    {
        $started = $dates() + $scheduled();

        $this->info($started.' workflow '.str('run')->plural($started).' started.');

        return self::SUCCESS;
    }
}
