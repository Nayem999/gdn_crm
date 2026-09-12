<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records money against an invoice.
 *
 * **The only writer of `amount_paid`.** The column is a sum kept so that "what
 * is outstanding" can be asked in SQL, and one writer is what stops it drifting
 * from the payments it summarises. It is recomputed from the rows rather than
 * incremented — an increment is a read-modify-write that two people recording
 * payments at once both lose.
 *
 * The whole thing is one transaction with the invoice row locked: without the
 * lock, two payments landing together each read the old sum and the second
 * overwrites the first, and the invoice is short by one payment that is
 * nonetheless sitting in the table.
 */
class RecordPaymentAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RuntimeException when the invoice cannot take a payment
     */
    public function __invoke(Invoice $invoice, array $attributes): Payment
    {
        if (! $invoice->status()->acceptsPayment()) {
            throw new RuntimeException(
                'A '.strtolower($invoice->status()->label()).' invoice cannot take a payment.'
            );
        }

        $amount = round((float) ($attributes['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new RuntimeException('A payment has to be for something.');
        }

        return DB::transaction(function () use ($invoice, $attributes, $amount): Payment {
            // Locked before the sum is read, or two payments landing together
            // each read the old total and the second overwrites the first.
            Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

            $payment = Payment::query()->create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'paid_on' => $attributes['paid_on'] ?? now()->toDateString(),
                'method' => (PaymentMethod::tryFrom((string) ($attributes['method'] ?? '')) ?? PaymentMethod::BankTransfer)->value,
                'reference' => trim((string) ($attributes['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($attributes['notes'] ?? '')) ?: null,
                'recorded_by' => auth()->id(),
            ]);

            $this->resum($invoice);

            return $payment;
        });
    }

    /**
     * Takes a payment back off an invoice.
     *
     * Deleted rather than negated: a payment recorded in error did not happen,
     * and a compensating negative row would make the payment history read as
     * two events when there were none. A genuine refund is a different thing
     * and belongs on a credit note.
     */
    public function remove(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $invoice = $payment->invoice;

            $payment->delete();

            if ($invoice !== null) {
                $this->resum($invoice);
            }
        });
    }

    /**
     * Bring the stored sum and the paid stamp into line with the payments.
     *
     * Recomputed from the rows, never incremented.
     */
    private function resum(Invoice $invoice): void
    {
        $paid = round((float) Payment::query()->where('invoice_id', $invoice->id)->sum('amount'), 2);
        $settled = round((float) $invoice->total - $paid, 2) <= 0.0 && $paid > 0.0;

        $invoice->forceFill([
            'amount_paid' => $paid,
            // Stamped when it is settled and cleared if a payment is taken back
            // off, so the stamp never claims a date for something that is no
            // longer true.
            'paid_at' => $settled ? ($invoice->paid_at ?? Carbon::now()) : null,
        ])->save();
    }
}
