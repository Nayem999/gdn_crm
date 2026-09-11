<?php

namespace Database\Factories;

use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Welcome a new lead',
            'description' => null,
            'module' => 'leads',
            'trigger_event' => WorkflowTrigger::RecordCreated->value,
            'trigger_field' => null,
            'date_offset_minutes' => null,
            'conditions' => FilterGroup::EMPTY,
            // Off by default. A factory that switched workflows on would have
            // every unrelated test creating records under an active automation.
            'is_active' => false,
            'position' => 0,
            'run_once_per_record' => false,
            'created_by' => User::factory(),
        ];
    }

    public function forModule(string $module): static
    {
        return $this->state(fn () => ['module' => $module]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function triggeredBy(WorkflowTrigger $trigger, ?string $field = null): static
    {
        return $this->state(fn () => [
            'trigger_event' => $trigger->value,
            'trigger_field' => $field,
        ]);
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function withConditions(array $conditions): static
    {
        return $this->state(fn () => ['conditions' => $conditions]);
    }
}
