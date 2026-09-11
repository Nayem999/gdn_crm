<?php

namespace Database\Factories;

use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowAction>
 */
class WorkflowActionFactory extends Factory
{
    protected $model = WorkflowAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'type' => WorkflowActionType::UpdateField->value,
            // Complete by default: the required keys for this type. A factory
            // producing incomplete steps would make every test that only wants
            // "a step" exercise the incomplete path by accident.
            'config' => ['field' => 'status', 'value' => 'contacted'],
            'position' => 0,
            'is_active' => true,
            'stop_on_failure' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function ofType(WorkflowActionType $type, array $config = []): static
    {
        return $this->state(fn () => ['type' => $type->value, 'config' => $config]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
