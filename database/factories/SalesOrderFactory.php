<?php

namespace Database\Factories;

use App\Domain\Sales\Enums\SalesOrderStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'SO-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'quote_id' => null,
            'account_id' => null,
            'contact_id' => null,
            'deal_id' => null,
            'bill_to_name' => 'Acme Ltd',
            'bill_to_address' => null,
            'bill_to_email' => 'buyer@example.com',
            'customer_reference' => null,
            'owner_id' => User::factory(),
            'status' => SalesOrderStatus::Draft->value,
            'tax_mode' => TaxMode::Exclusive->value,
            'order_date' => now()->toDateString(),
            'expected_date' => null,
            'notes' => null,
        ];
    }

    public function withStatus(SalesOrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }
}
