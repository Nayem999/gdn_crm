<?php

namespace Database\Factories;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'workflow_run_id' => null,
            'workflow_action_id' => null,
            'workflow_name' => 'Approve the discount',
            'module' => 'leads',
            'subject_type' => null,
            'subject_id' => null,
            'summary' => 'Approve the discount on this lead',
            'status' => ApprovalStatus::Waiting->value,
            'current_level' => 0,
            'resume_from_position' => 1,
            'requested_at' => now(),
        ];
    }

    public function forRun(WorkflowRun $run): static
    {
        return $this->state(fn () => [
            'workflow_id' => $run->workflow_id,
            'workflow_run_id' => $run->id,
            'workflow_name' => $run->workflow_name,
            'module' => $run->module,
        ]);
    }

    public function about(Model $subject): static
    {
        return $this->state(fn () => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function withStatus(ApprovalStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'completed_at' => $status->isOpen() ? null : now(),
        ]);
    }
}
