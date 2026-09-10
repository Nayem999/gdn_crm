<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' opportunity',
            'account_id' => Account::factory(),
            'contact_id' => null,
            'lead_id' => null,
            'value' => fake()->randomFloat(2, 1000, 250000),
            'expected_close_date' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'),
            'stage' => DealStage::New->value,
            'owner_id' => User::factory(),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn () => ['account_id' => $account->id]);
    }

    public function stage(DealStage $stage): static
    {
        return $this->state(fn () => ['stage' => $stage->value]);
    }

    public function worth(string $value): static
    {
        return $this->state(fn () => ['value' => $value]);
    }

    public function onPipeline(Pipeline $pipeline, ?string $stageKey = null): static
    {
        return $this->state(fn () => [
            'pipeline_id' => $pipeline->id,
            'stage' => $stageKey ?? $pipeline->stages->first()?->key ?? DealStage::New->value,
        ]);
    }

    public function atStage(string $stageKey): static
    {
        return $this->state(fn () => ['stage' => $stageKey]);
    }

    /**
     * A deal that has already ended, with the reason recorded.
     *
     * The reason has to match the outcome or the win/loss report is nonsense,
     * so it is derived rather than passed in.
     */
    public function closed(StageOutcome $outcome = StageOutcome::Won, ?string $stageKey = null): static
    {
        $reason = DealCloseReason::forOutcome($outcome)[0];

        return $this->state(fn () => [
            'stage' => $stageKey ?? ($outcome === StageOutcome::Won ? DealStage::Won->value : DealStage::Lost->value),
            'closed_at' => now(),
            'close_reason' => $reason->value,
        ]);
    }

    public function closingOn(string $date): static
    {
        return $this->state(fn () => ['expected_close_date' => $date]);
    }
}
