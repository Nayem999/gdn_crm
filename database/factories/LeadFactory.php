<?php

namespace Database\Factories;

use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'job_title' => fake()->jobTitle(),
            'company_name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'mobile' => fake()->phoneNumber(),
            'website' => 'https://'.fake()->domainName(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'status' => LeadStatus::New->value,
            'source' => fake()->randomElement(LeadSource::cases())->value,
            'estimated_value' => fake()->randomFloat(2, 500, 250000),
            'description' => fake()->sentence(),
            'status_changed_at' => now(),
        ];
    }

    /**
     * A fresh assignee whenever nothing else in the chain provided one —
     * `assignees` is no longer a plain column, so `definition()` cannot fill
     * it, and a lead with none is invisible below `all` access, which would
     * make an ordinary factory-built lead invisible in most visibility tests
     * for no reason the test itself states.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Lead $lead) {
            if ($lead->assignees()->doesntExist()) {
                LeadAssignee::create([
                    'lead_id' => $lead->id,
                    'user_id' => User::factory()->create()->id,
                    'priority' => null,
                    'assigned_at' => now(),
                ]);
            }
        });
    }

    /**
     * The lead's one and only assignee, unprioritised — what `owner_id` used
     * to mean. Wipes any other assignee this chain already produced (or that
     * `configure()`'s own default is about to, whichever runs first), so
     * `Lead::factory()->ownedBy($user)->create()` always ends with exactly
     * $user on it, regardless of callback order.
     */
    public function ownedBy(User $user): static
    {
        return $this->afterCreating(function (Lead $lead) use ($user) {
            $lead->assignees()->delete();

            LeadAssignee::create([
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'priority' => null,
                'assigned_at' => now(),
            ]);
        });
    }

    /**
     * One more assignee alongside whoever else is already on the lead — for
     * tests of the multi-assign concept itself, where `ownedBy()`'s
     * single-assignee replacement is the wrong shape.
     */
    public function assignedTo(User $user, ?int $priority = null): static
    {
        return $this->afterCreating(function (Lead $lead) use ($user, $priority) {
            LeadAssignee::create([
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'priority' => $priority,
                'assigned_at' => now(),
            ]);
        });
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'status_changed_at' => now(),
        ]);
    }

    public function source(LeadSource $source): static
    {
        return $this->state(fn () => ['source' => $source->value]);
    }

    public function named(string $first, string $last): static
    {
        return $this->state(fn () => ['first_name' => $first, 'last_name' => $last]);
    }

    public function worth(string $value): static
    {
        return $this->state(fn () => ['estimated_value' => $value]);
    }

    /**
     * Sitting untouched, for the stalled-lead checks.
     */
    public function stalledFor(int $days): static
    {
        return $this->state(fn () => ['status_changed_at' => now()->subDays($days)]);
    }
}
