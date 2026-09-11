<?php

namespace Database\Factories;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalLevel;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ApprovalLevel>
 */
class ApprovalLevelFactory extends Factory
{
    protected $model = ApprovalLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_request_id' => ApprovalRequest::factory(),
            'position' => 0,
            'approver_id' => User::factory(),
            'status' => ApprovalStatus::Waiting->value,
            // No deadline by default: a test about escalation should have to
            // say so, rather than every unrelated fixture quietly expiring.
            'due_at' => null,
        ];
    }

    public function at(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    public function forApprover(User $user): static
    {
        return $this->state(fn () => ['approver_id' => $user->id]);
    }

    public function dueAt(Carbon $moment): static
    {
        return $this->state(fn () => ['due_at' => $moment]);
    }
}
