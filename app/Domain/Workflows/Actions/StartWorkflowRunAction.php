<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Claims one occasion for one workflow, and logs it.
 *
 * "Fires exactly once" lives here, and it is enforced by a unique index rather
 * than by looking first. A check-then-insert is something two queue workers, or
 * two overlapping cron sweeps, both pass — so the claim *is* the insert, and
 * losing the race is an ordinary outcome rather than an error.
 *
 * The run is created **Pending**. Deciding whether the conditions match is 5.3
 * and doing the work is 5.4; this is the trigger engine, and its whole job is
 * to produce exactly one run per occasion.
 */
class StartWorkflowRunAction
{
    /**
     * @param  array<string, mixed>  $context  What the trigger saw.
     * @return WorkflowRun|null Null when this occasion was already claimed.
     */
    public function __invoke(
        Workflow $workflow,
        WorkflowTrigger $trigger,
        ?Model $subject = null,
        array $context = [],
        ?string $dedupeKey = null,
    ): ?WorkflowRun {
        // A workflow set to run once per record has already had its turn. This
        // is a read, so two racing workers could both pass it — the unique key
        // below is what actually settles that, and this only saves the insert
        // in the ordinary case.
        if ($subject !== null && $workflow->run_once_per_record && $this->alreadyRan($workflow, $subject)) {
            return null;
        }

        try {
            $run = WorkflowRun::query()->create([
                'workflow_id' => $workflow->id,
                // Copied, so the log still reads as something after the
                // workflow is deleted.
                'workflow_name' => $workflow->name,
                'module' => $workflow->module(),
                'trigger_event' => $trigger->value,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'status' => WorkflowRunStatus::Pending->value,
                'dedupe_key' => $dedupeKey,
                'context' => $context === [] ? null : $context,
                'started_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Somebody else claimed this occasion first. That is the unique
            // index doing its job, not a failure — but only for a duplicate
            // key; anything else is a real problem and must not be swallowed.
            if ($this->isDuplicate($exception)) {
                return null;
            }

            throw $exception;
        }

        // Nothing is written back to the workflow. A counter beside the log
        // would be a second version of what the log already knows, bought with
        // an UPDATE of one row on every event — see the 5.2 migration that
        // removed the pair.
        return $run;
    }

    private function alreadyRan(Workflow $workflow, Model $subject): bool
    {
        return $workflow->runs()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->exists();
    }

    /**
     * Whether this was the unique index refusing a second claim.
     *
     * Matched on the SQLSTATE integrity-violation class rather than on the
     * message, which differs between MySQL and MariaDB and is translated.
     */
    private function isDuplicate(QueryException $exception): bool
    {
        return $exception->getCode() === '23000';
    }
}
