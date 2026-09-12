<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Sales\Documents\DocumentNumber;
use App\Domain\Sales\Enums\PurchaseOrderStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\PurchaseOrder;
use App\Domain\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * Raises a purchase order, optionally against a sales order it is fulfilling.
 *
 * The supplier's details are snapshotted like every other document's
 * counterparty: a purchase order sent last year says who it was sent to, not
 * who that account is called now.
 */
class CreatePurchaseOrderAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes): PurchaseOrder
    {
        return DB::transaction(function () use ($attributes): PurchaseOrder {
            $supplier = $this->supplier($attributes);
            $salesOrder = $this->salesOrder($attributes);

            $order = new PurchaseOrder;

            $name = trim((string) ($attributes['supplier_name'] ?? ''));

            $order->forceFill([
                'number' => DocumentNumber::next('purchase_order', 'PO'),
                'supplier_account_id' => $supplier?->id,
                'supplier_name' => $name !== '' ? $name : ($supplier !== null ? $supplier->name : 'Supplier'),
                'supplier_address' => trim((string) ($attributes['supplier_address'] ?? '')) ?: null,
                'supplier_email' => trim((string) ($attributes['supplier_email'] ?? '')) ?: ($supplier !== null ? $supplier->email : null),
                'sales_order_id' => $salesOrder?->id,
                'owner_id' => (int) ($attributes['owner_id'] ?? auth()->id()),
                'status' => PurchaseOrderStatus::Draft->value,
                'tax_mode' => (TaxMode::tryFrom((string) ($attributes['tax_mode'] ?? '')) ?? TaxMode::Exclusive)->value,
                'order_date' => $attributes['order_date'] ?? now()->toDateString(),
                'expected_date' => $attributes['expected_date'] ?? null,
                'notes' => trim((string) ($attributes['notes'] ?? '')) ?: null,
            ])->save();

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function supplier(array $attributes): ?Account
    {
        $id = (int) ($attributes['supplier_account_id'] ?? 0);

        return $id > 0 ? Account::query()->whereKey($id)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function salesOrder(array $attributes): ?SalesOrder
    {
        $id = (int) ($attributes['sales_order_id'] ?? 0);

        return $id > 0 ? SalesOrder::query()->whereKey($id)->first() : null;
    }
}
