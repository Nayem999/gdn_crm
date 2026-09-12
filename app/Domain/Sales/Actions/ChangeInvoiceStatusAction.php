<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only thing that moves an invoice's status.
 *
 * Cancelling an invoice that has money against it is refused: the money is
 * real, and a cancelled invoice holding payments is a reconciliation somebody
 * will have to unpick by hand. Take the payments off first, or raise a credit
 * note.
 */
class ChangeInvoiceStatusAction
{
    /**
     * @throws RuntimeException when the move is not one the current status allows
     */
    public function __invoke(Invoice $invoice, InvoiceStatus $target, ?Carbon $at = null): Invoice
    {
        $current = $invoice->status();
        $at ??= Carbon::now();

        if ($current === $target) {
            return $invoice;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(
                'A '.strtolower($current->label()).' invoice cannot be marked '.strtolower($target->label()).'.'
            );
        }

        if ($target === InvoiceStatus::Cancelled && (float) $invoice->amount_paid > 0) {
            throw new RuntimeException(
                'This invoice has payments against it. Take those off first, or raise a credit note.'
            );
        }

        if ($target === InvoiceStatus::Issued && $invoice->lines()->doesntExist()) {
            throw new RuntimeException('This invoice has no lines on it yet.');
        }

        $invoice->forceFill([
            'status' => $target->value,
            ...match ($target) {
                InvoiceStatus::Issued => ['issued_at' => $at],
                InvoiceStatus::Cancelled => ['cancelled_at' => $at],
                default => [],
            },
        ])->save();

        return $invoice->fresh() ?? $invoice;
    }
}
