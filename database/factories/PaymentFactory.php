<?php

namespace Database\Factories;

use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => 100,
            'paid_on' => now()->toDateString(),
            'method' => PaymentMethod::BankTransfer->value,
            'reference' => null,
            'notes' => null,
            'recorded_by' => null,
        ];
    }

    public function of(float $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }
}
