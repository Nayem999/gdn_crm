<?php

namespace Database\Factories;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Models\SlaPolicy;
use App\Domain\Support\Models\SlaTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaTarget>
 */
class SlaTargetFactory extends Factory
{
    protected $model = SlaTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sla_policy_id' => SlaPolicy::factory(),
            'priority' => TicketPriority::Normal->value,
            'first_response_minutes' => 60,
            'resolution_minutes' => 60 * 24,
        ];
    }

    public function forPriority(TicketPriority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority->value]);
    }

    /**
     * Both halves of the promise, set together — a target row that says nothing
     * is a real state (no promise here) and must be asked for on purpose.
     */
    public function promising(?int $responseMinutes, ?int $resolutionMinutes): static
    {
        return $this->state(fn () => [
            'first_response_minutes' => $responseMinutes,
            'resolution_minutes' => $resolutionMinutes,
        ]);
    }
}
