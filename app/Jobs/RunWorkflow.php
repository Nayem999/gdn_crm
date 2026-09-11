<?php

namespace App\Jobs;

use App\Domain\Workflows\Actions\RunWorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Carries out one workflow run, off the request.
 *
 * Queued rather than inline because a run can send email and call a webhook,
 * and the person who saved a lead should not wait ten seconds for somebody
 * else's server to answer.
 *
 * Carries the run's **id**, not the model: by the time the job is picked up the
 * row may have moved on, and the executor's first act is to check its status.
 * A run that is no longer pending is left alone, which is what makes a
 * double-delivered job harmless.
 */
class RunWorkflow implements ShouldQueue
{
    use Queueable;

    /**
     * Once. A run that failed is retried deliberately from the log viewer
     * (5.8), by a person who has seen why it failed — an automatic retry of an
     * action that half-succeeded would repeat the half that worked.
     */
    public int $tries = 1;

    public function __construct(private readonly int $runId) {}

    public function handle(RunWorkflowAction $run): void
    {
        $record = WorkflowRun::query()->whereKey($this->runId)->first();

        if ($record === null) {
            return;
        }

        $run($record);
    }
}
