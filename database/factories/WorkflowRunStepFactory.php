<?php

namespace Database\Factories;

use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowRunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRunStep>
 */
class WorkflowRunStepFactory extends Factory
{
    protected $model = WorkflowRunStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'workflow_action_id' => null,
            'action_type' => WorkflowActionType::UpdateField->value,
            'position' => 0,
            'status' => WorkflowRunStatus::Success->value,
            'message' => null,
            'result' => null,
            'attempts' => 1,
            'duration_ms' => 4,
        ];
    }

    public function forAction(WorkflowAction $action): static
    {
        return $this->state(fn () => [
            'workflow_action_id' => $action->id,
            'action_type' => $action->type()->value,
            'position' => $action->position,
        ]);
    }

    public function withStatus(WorkflowRunStatus $status, ?string $message = null): static
    {
        return $this->state(fn () => ['status' => $status->value, 'message' => $message]);
    }
}
