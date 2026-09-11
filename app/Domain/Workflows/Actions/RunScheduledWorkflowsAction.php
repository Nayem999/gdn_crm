<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Settings\DisplayTime;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Triggers\WorkflowDispatcher;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Fires the workflows whose schedule is due.
 *
 * Two things about the clock, both deliberate:
 *
 * - **The expression is read in the office timezone.** Somebody writing "every
 *   weekday at 9" means nine where they work, and this installation stores
 *   everything in UTC. `DisplayTime` is the one converter, so it is the one
 *   used here too.
 * - **The claim is the minute.** The sweep runs every minute and a cron
 *   expression is only accurate to the minute, so the dedupe key is the wall
 *   clock minute it was due. A sweep that runs twice in the same minute, or a
 *   retried job, claims the same key and the second one loses.
 */
class RunScheduledWorkflowsAction
{
    public function __construct(private readonly WorkflowDispatcher $dispatcher) {}

    /**
     * @return int the number of runs started
     */
    public function __invoke(?Carbon $now = null): int
    {
        // The wall clock somebody wrote the schedule against.
        $officeNow = DisplayTime::display($now ?? Carbon::now());
        $started = 0;

        $workflows = Workflow::query()
            ->withTrigger(WorkflowTrigger::Scheduled)
            ->with('actions')
            ->get();

        foreach ($workflows as $workflow) {
            if (! $workflow->isRunnable() || ! $this->isDue($workflow, $officeNow)) {
                continue;
            }

            $run = $this->dispatcher->occasion(
                $workflow,
                sprintf('w%d:scheduled:%s', $workflow->id, $officeNow->format('Y-m-d H:i')),
                ['due_at' => $officeNow->format('Y-m-d H:i'), 'schedule' => $workflow->schedule_expression],
            );

            if ($run !== null) {
                $started++;
            }
        }

        return $started;
    }

    private function isDue(Workflow $workflow, Carbon $officeNow): bool
    {
        // isRunnable() has already refused an unparseable expression, so this
        // only ever parses one the validator accepted.
        return (new CronExpression((string) $workflow->schedule_expression))->isDue($officeNow);
    }
}
