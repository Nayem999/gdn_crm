<?php

namespace Database\Factories;

use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadAssignee>
 */
class LeadAssigneeFactory extends Factory
{
    protected $model = LeadAssignee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'user_id' => User::factory(),
            'priority' => null,
            'assigned_at' => now(),
        ];
    }

    public function prioritised(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
