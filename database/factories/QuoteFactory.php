<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Unique per row rather than through the counter: a factory that
            // took real numbers would make every test depend on how many quotes
            // the ones before it created.
            'number' => 'Q-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'version' => 1,
            'root_id' => null,
            'account_id' => null,
            'contact_id' => null,
            'deal_id' => null,
            'bill_to_name' => 'Acme Ltd',
            'bill_to_address' => "1 Example Street\nDhaka",
            'bill_to_email' => 'buyer@example.com',
            'owner_id' => User::factory(),
            'status' => QuoteStatus::Draft->value,
            'tax_mode' => TaxMode::Exclusive->value,
            'price_book_id' => null,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'intro' => null,
            'terms' => null,
            'notes' => null,
        ];
    }

    public function withStatus(QuoteStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'sent_at' => $status === QuoteStatus::Draft ? null : now(),
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function for_(Account $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account->id,
            'bill_to_name' => $account->name,
        ]);
    }

    public function taxInclusive(): static
    {
        return $this->state(fn () => ['tax_mode' => TaxMode::Inclusive->value]);
    }

    public function noEmail(): static
    {
        return $this->state(fn () => ['bill_to_email' => null]);
    }
}
