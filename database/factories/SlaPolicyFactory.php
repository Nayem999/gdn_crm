<?php

namespace Database\Factories;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaPolicy>
 */
class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Standard support',
            'description' => fake()->sentence(),
            'is_default' => false,
            'is_active' => true,
            'warn_at_percent' => SlaPolicy::DEFAULT_WARN_PERCENT,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function warningAt(int $percent): static
    {
        return $this->state(fn () => ['warn_at_percent' => $percent]);
    }

    /**
     * A policy that promises the same thing at every priority.
     *
     * The quickest fixture for a clock test, which usually cares about one
     * duration rather than about triage.
     */
    public function promising(?int $responseMinutes, ?int $resolutionMinutes): static
    {
        return $this->afterCreating(function (SlaPolicy $policy) use ($responseMinutes, $resolutionMinutes) {
            foreach (TicketPriority::cases() as $priority) {
                $policy->targets()->create([
                    'priority' => $priority->value,
                    'first_response_minutes' => $responseMinutes,
                    'resolution_minutes' => $resolutionMinutes,
                ]);
            }

            $policy->load('targets');
        });
    }
}
