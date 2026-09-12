<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Documents\DocumentNumber;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raises an invoice from a confirmed sales order.
 *
 * Lines copied exactly, for the same reason the quote-to-order conversion
 * copies them: what was agreed is what is owed.
 *
 * **Not from a draft order.** Invoicing something nobody confirmed is how a
 * customer receives a bill for an order they never placed. Unlike the
 * quote-to-order conversion this does *not* refuse a second invoice — part
 * invoicing an order is ordinary, and the invoices simply reference the same
 * order.
 */
class ConvertOrderToInvoiceAction
{
    /**
     * @throws RuntimeException when the order is not one an invoice can be raised from
     */
    public function __invoke(SalesOrder $order, ?int $dueInDays = null): Invoice
    {
        if (! $order->status()->canBeInvoiced()) {
            throw new RuntimeException(
                'A '.strtolower($order->status()->label()).' order cannot be invoiced.'
            );
        }

        return DB::transaction(function () use ($order, $dueInDays): Invoice {
            $invoice = new Invoice;

            $invoice->forceFill([
                'number' => DocumentNumber::next('invoice', 'INV'),
                'sales_order_id' => $order->id,
                'quote_id' => $order->quote_id,
                'account_id' => $order->account_id,
                'contact_id' => $order->contact_id,
                'bill_to_name' => $order->bill_to_name,
                'bill_to_address' => $order->bill_to_address,
                'bill_to_email' => $order->bill_to_email,
                'owner_id' => $order->owner_id,
                'status' => InvoiceStatus::Draft->value,
                'tax_mode' => $order->tax_mode,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays($dueInDays ?? 30)->toDateString(),
                'notes' => $order->notes,
                'subtotal' => $order->subtotal,
                'discount_total' => $order->discount_total,
                'tax_total' => $order->tax_total,
                'total' => $order->total,
            ])->save();

            foreach ($order->lines()->get() as $line) {
                $copy = $line->replicate(['document_type', 'document_id']);

                $copy->forceFill([
                    'document_type' => $invoice->getMorphClass(),
                    'document_id' => $invoice->id,
                ])->save();
            }

            return $invoice->fresh() ?? $invoice;
        });
    }
}
