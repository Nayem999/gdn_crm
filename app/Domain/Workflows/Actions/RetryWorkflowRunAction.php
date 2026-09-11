<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Jobs\RunWorkflow;
use RuntimeException;

/**
 * Puts a failed run back on the queue.
 *
 * **From the step that failed, not from the beginning.** The steps before it
 * succeeded, and repeating them would send a second email, create a second
 * follow-up task, call a webhook twice — a retry that does the work twice is
 * worse than one that does nothing. The machinery is the same
 * `resume_from_position` an approval uses, which is why that column is not
 * named after approvals.
 *
 * The steps already recorded stay. A retry appends its own rows, so the log
 * shows both attempts — somebody asking "did this ever work" needs to see that
 * it failed at nine and succeeded at ten, not only the second half.
 */
class RetryWorkflowRunAction
{
    /**
     * @throws RuntimeException when this run is not one that can be retried
     */
    public function __invoke(WorkflowRun $run): WorkflowRun
    {
        if (! $run->status()->isRetryable()) {
            throw new RuntimeException('Only a failed run can be retried.');
        }

        if ($run->workflow === null) {
            throw new RuntimeException('The workflow this ran for has been removed.');
        }

        $run->forceFill([
            'status' => WorkflowRunStatus::Pending->value,
            'resume_from_position' => $this->failedAt($run),
            // Cleared, so a run that fails again does not show the first
            // attempt's reason against the second.
            'message' => null,
            'finished_at' => null,
            'duration_ms' => null,
            // The clock restarts: the duration should describe this attempt.
            'started_at' => now(),
        ])->save();

        RunWorkflow::dispatch($run->id);

        return $run->fresh() ?? $run;
    }

    /**
     * Where it went wrong.
     *
     * The first failed step, because a run stops at the first failure that says
     * to stop — and when it did not stop, the earlier ones after that failure
     * ran anyway and will run again, which is the price of retrying a workflow
     * that was told to carry on through failures.
     */
    private function failedAt(WorkflowRun $run): int
    {
        $failed = $run->steps()
            ->where('status', WorkflowRunStatus::Failed->value)
            ->orderBy('position')
            ->first();

        return $failed === null ? 0 : $failed->position;
    }
}
