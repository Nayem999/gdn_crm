<?php

namespace Database\Factories;

use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    protected $model = WorkflowRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'workflow_name' => 'Welcome a new lead',
            'module' => 'leads',
            'trigger_event' => WorkflowTrigger::RecordCreated->value,
            'subject_type' => null,
            'subject_id' => null,
            'status' => WorkflowRunStatus::Success->value,
            'dedupe_key' => null,
            'message' => null,
            'context' => null,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 12,
        ];
    }

    public function forWorkflow(Workflow $workflow): static
    {
        return $this->state(fn () => [
            'workflow_id' => $workflow->id,
            'workflow_name' => $workflow->name,
            'module' => $workflow->module(),
            'trigger_event' => $workflow->trigger_event,
        ]);
    }

    public function about(Model $subject): static
    {
        return $this->state(fn () => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function withStatus(WorkflowRunStatus $status, ?string $message = null): static
    {
        return $this->state(fn () => ['status' => $status->value, 'message' => $message]);
    }

    public function failed(string $message = 'Something went wrong.'): static
    {
        return $this->withStatus(WorkflowRunStatus::Failed, $message);
    }
}
