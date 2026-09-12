<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Documents\DocumentNumber;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\SalesOrderStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns an accepted quote into a sales order.
 *
 * **The lines are copied exactly**, figures included — not recalculated from
 * the products. What the customer accepted is what they are owed, and
 * recomputing would quietly reprice the order if the catalogue, a price book or
 * a tax rate had moved in between. The stored figures on a quote line are the
 * agreement.
 *
 * **Only an accepted quote converts**, and **only once**. An order raised from
 * a quote nobody accepted is a commitment nobody made; two orders from one
 * quote is the customer being asked to pay twice.
 */
class ConvertQuoteToOrderAction
{
    /**
     * @throws RuntimeException when the quote is not one an order can be raised from
     */
    public function __invoke(Quote $quote, ?string $customerReference = null): SalesOrder
    {
        if ($quote->status() !== QuoteStatus::Accepted) {
            throw new RuntimeException(
                'Only an accepted quote becomes an order. This one is '.strtolower($quote->status()->label()).'.'
            );
        }

        $existing = SalesOrder::query()->where('quote_id', $quote->id)->first();

        if ($existing !== null) {
            throw new RuntimeException('This quote is already on order '.$existing->number.'.');
        }

        return DB::transaction(function () use ($quote, $customerReference): SalesOrder {
            $order = new SalesOrder;

            $order->forceFill([
                'number' => DocumentNumber::next('sales_order', 'SO'),
                'quote_id' => $quote->id,
                'account_id' => $quote->account_id,
                'contact_id' => $quote->contact_id,
                'deal_id' => $quote->deal_id,
                // The quote's snapshot, carried across rather than re-derived:
                // the order is for whoever the quote was for.
                'bill_to_name' => $quote->bill_to_name,
                'bill_to_address' => $quote->bill_to_address,
                'bill_to_email' => $quote->bill_to_email,
                'customer_reference' => $customerReference,
                'owner_id' => $quote->owner_id,
                'status' => SalesOrderStatus::Draft->value,
                'tax_mode' => $quote->tax_mode,
                'order_date' => now()->toDateString(),
                'notes' => $quote->notes,
                // Copied wholesale, so the order totals what the quote totalled
                // even if a line's product has since changed.
                'subtotal' => $quote->subtotal,
                'discount_total' => $quote->discount_total,
                'tax_total' => $quote->tax_total,
                'total' => $quote->total,
            ])->save();

            $this->copyLines($quote, $order);

            return $order->fresh() ?? $order;
        });
    }

    /**
     * Every line, with its figures.
     *
     * `replicate()` rather than a hand-written field list: a field added to
     * `document_lines` later is then carried across automatically, and the
     * alternative is a conversion that silently drops whatever somebody forgot
     * to add here.
     */
    private function copyLines(Quote $quote, SalesOrder $order): void
    {
        foreach ($quote->lines()->get() as $line) {
            $copy = $line->replicate(['document_type', 'document_id']);

            $copy->forceFill([
                'document_type' => $order->getMorphClass(),
                'document_id' => $order->id,
            ])->save();
        }
    }
}
