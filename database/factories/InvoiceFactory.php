<?php

namespace Database\Factories;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'INV-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'sales_order_id' => null,
            'quote_id' => null,
            'account_id' => null,
            'contact_id' => null,
            'bill_to_name' => 'Acme Ltd',
            'bill_to_address' => null,
            'bill_to_email' => 'buyer@example.com',
            'owner_id' => User::factory(),
            'status' => InvoiceStatus::Draft->value,
            'tax_mode' => TaxMode::Exclusive->value,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'terms' => null,
            'notes' => null,
            'total' => 1000,
            'subtotal' => 1000,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Issued->value, 'issued_at' => now()]);
    }

    public function totalling(float $total): static
    {
        return $this->state(fn () => ['total' => $total, 'subtotal' => $total]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn () => ['due_date' => $date]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }
}
