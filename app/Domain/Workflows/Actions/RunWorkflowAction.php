<?php

namespace App\Domain\Workflows\Actions;

use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Handlers\WorkflowActionRegistry;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowRunStep;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Domain\Workflows\Triggers\WorkflowSuppressor;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carries out one run, step by step, and writes down what happened.
 *
 * Four things worth knowing:
 *
 * - **Only a pending run is executed.** A queue that delivers a job twice must
 *   not do the work twice, and the status is the claim. Anything already
 *   running or finished is left alone.
 * - **Every step writes a row, whatever it did.** A skipped step is as much a
 *   part of the account as a successful one — "there was no address to send to"
 *   is the answer somebody is looking for when they ask why no email arrived.
 * - **The whole execution suppresses triggers.** Otherwise an action that sets
 *   a field fires the update trigger, which runs the workflow, which sets the
 *   field.
 * - **A step that throws is caught.** Handlers are meant to return failure
 *   rather than throw, but a run must not be left Running by something
 *   unforeseen — a row stuck in that state is invisible to a retry and to the
 *   health figures alike.
 */
class RunWorkflowAction
{
    public function __construct(private readonly WorkflowSuppressor $suppressor) {}

    public function __invoke(WorkflowRun $run): WorkflowRun
    {
        if ($run->status() !== WorkflowRunStatus::Pending) {
            return $run;
        }

        $workflow = $run->workflow;

        if ($workflow === null) {
            return $this->finish($run, WorkflowRunStatus::Skipped, 'The workflow was removed before this run started.');
        }

        $subject = $this->subject($run);

        if ($run->subject_id !== null && $subject === null) {
            // Deleted between the trigger and the run. Not a failure — there is
            // simply nothing left to act on.
            return $this->finish($run, WorkflowRunStatus::Skipped, 'The record no longer exists.');
        }

        $steps = $workflow->actions()->where('is_active', true)->get();

        if ($steps->isEmpty()) {
            return $this->finish($run, WorkflowRunStatus::Skipped, 'The workflow has no steps switched on.');
        }

        $run->forceFill(['status' => WorkflowRunStatus::Running->value])->save();

        $context = new WorkflowContext(
            workflow: $workflow,
            run: $run,
            subject: $subject,
            trigger: is_array($run->context) ? $run->context : [],
        );

        return $this->execute($run, $steps->all(), $context);
    }

    /**
     * @param  array<int, WorkflowAction>  $steps
     */
    private function execute(WorkflowRun $run, array $steps, WorkflowContext $context): WorkflowRun
    {
        $failed = null;

        // One suppression around the whole run rather than one per step: an
        // action that creates a record which another action then edits must not
        // raise triggers in between either.
        $this->suppressor->while(function () use ($steps, $context, $run, &$failed): void {
            foreach ($steps as $step) {
                $outcome = $this->runStep($step, $context);

                $this->record($run, $step, $outcome);

                if ($outcome->failedOutright()) {
                    $failed ??= $outcome->message;

                    // A courtesy email failing should not stop the follow-up
                    // task being created; writing a field probably should.
                    if ($step->stop_on_failure) {
                        return;
                    }
                }
            }
        });

        return $failed === null
            ? $this->finish($run, WorkflowRunStatus::Success, null)
            : $this->finish($run, WorkflowRunStatus::Failed, $failed);
    }

    private function runStep(WorkflowAction $step, WorkflowContext $context): WorkflowStepOutcome
    {
        if (! $step->isComplete()) {
            // A step missing what its type needs would otherwise fail once per
            // firing forever, burying the real failures.
            return WorkflowStepOutcome::skipped('This step is not finished being set up.');
        }

        try {
            return WorkflowActionRegistry::for($step->type())->handle($step, $context);
        } catch (Throwable $unforeseen) {
            Log::error('Workflow step threw', [
                'workflow' => $context->workflow->id,
                'run' => $context->run->id,
                'step' => $step->id,
                'exception' => $unforeseen->getMessage(),
            ]);

            // The message, never the trace: a log row is read by people, and a
            // stack trace can carry values from the record it was handling.
            return WorkflowStepOutcome::failed($unforeseen->getMessage());
        }
    }

    private function record(WorkflowRun $run, WorkflowAction $step, WorkflowStepOutcome $outcome): void
    {
        WorkflowRunStep::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_action_id' => $step->id,
            // Its own copy, so editing the workflow afterwards cannot rewrite
            // what this step was.
            'action_type' => $step->type()->value,
            'position' => $step->position,
            'status' => $outcome->status->value,
            'message' => $outcome->message,
            'result' => $outcome->result === [] ? null : $outcome->result,
            'attempts' => 1,
        ]);
    }

    private function finish(WorkflowRun $run, WorkflowRunStatus $status, ?string $message): WorkflowRun
    {
        $finishedAt = now();

        $run->forceFill([
            'status' => $status->value,
            'message' => $message,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $run->started_at->diffInMilliseconds($finishedAt)),
        ])->save();

        return $run;
    }

    /**
     * The record this ran for, soft-deleted or not.
     *
     * Resolved through the registry rather than `$run->subject`, because a
     * delete trigger's record is soft-deleted and the relation's own scope
     * would report it missing — every delete workflow would skip itself.
     */
    private function subject(WorkflowRun $run): ?Model
    {
        if ($run->subject_id === null) {
            return null;
        }

        $model = WorkflowModules::modelClass($run->module());

        if ($model === null) {
            return null;
        }

        $query = $model::query()->whereKey($run->subject_id);

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->first();
    }
}
