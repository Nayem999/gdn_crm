<?php

namespace Database\Factories;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\CampaignType;
use App\Domain\Campaigns\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-6 months', '+1 month');

        return [
            'name' => ucfirst(fake()->unique()->words(3, true)).' campaign',
            'type' => fake()->randomElement(CampaignType::cases())->value,
            'status' => CampaignStatus::Active->value,
            'description' => fake()->sentence(),
            'start_date' => $start->format('Y-m-d'),
            // Always after the start: SaveCampaignAction refuses the other way
            // round, so a fixture that produced it would describe something the
            // application cannot make.
            'end_date' => fake()->dateTimeBetween($start, '+4 months')->format('Y-m-d'),
            'budget' => fake()->randomFloat(2, 1000, 200000),
            'actual_cost' => fake()->randomFloat(2, 0, 150000),
            'expected_revenue' => fake()->randomFloat(2, 5000, 800000),
            'code' => strtoupper(fake()->unique()->bothify('CMP-####')),
            'owner_id' => User::factory(),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function ofType(CampaignType $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }

    public function withStatus(CampaignStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    /**
     * A campaign with no money against it at all — the state every figure
     * derived from cost has to cope with.
     */
    public function unbudgeted(): static
    {
        return $this->state(fn () => [
            'budget' => null,
            'actual_cost' => null,
            'expected_revenue' => null,
        ]);
    }

    /**
     * Running now, whatever today is.
     */
    public function running(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Active->value,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Completed->value,
            'start_date' => now()->subMonths(4)->toDateString(),
            'end_date' => now()->subMonth()->toDateString(),
        ]);
    }

    public function costing(float $cost, ?float $budget = null): static
    {
        return $this->state(fn () => [
            'actual_cost' => $cost,
            'budget' => $budget ?? $cost,
        ]);
    }
}
