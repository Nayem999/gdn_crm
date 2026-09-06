<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
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
}
