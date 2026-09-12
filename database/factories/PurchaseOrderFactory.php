<?php

namespace Database\Factories;

use App\Domain\Sales\Enums\PurchaseOrderStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'PO-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'supplier_account_id' => null,
            'supplier_name' => 'Parts Supplier Ltd',
            'supplier_address' => null,
            'supplier_email' => 'sales@supplier.example',
            'sales_order_id' => null,
            'owner_id' => User::factory(),
            'status' => PurchaseOrderStatus::Draft->value,
            'tax_mode' => TaxMode::Exclusive->value,
            'order_date' => now()->toDateString(),
            'expected_date' => null,
            'notes' => null,
        ];
    }

    public function withStatus(PurchaseOrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }
}
