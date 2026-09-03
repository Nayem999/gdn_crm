<?php

namespace Database\Factories;

use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
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
            'owner_id' => User::factory(),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
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
