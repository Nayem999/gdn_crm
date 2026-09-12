<?php

namespace App\Domain\Sales\Enums;

/**
 * How much of an invoice has been paid.
 *
 * **Derived, never stored.** It is a function of the total and what has been
 * received, so there is nothing to keep in step and nothing to drift.
 * `Invoice::paymentState()` is the only thing that produces one.
 *
 * Overpayment is deliberately `Paid` rather than a case of its own: money over
 * the total is a credit to sort out, not a state of this invoice, and a
 * separate case here would put "overpaid" in every filter dropdown for
 * something that happens twice a year.
 */
enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Part paid',
            self::Paid => 'Paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'slate',
            self::PartiallyPaid => 'amber',
            self::Paid => 'emerald',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Paid;
    }

    /**
     * What an invoice with this much paid is in.
     *
     * The comparison is to the penny: floating point makes 0.1 + 0.2 not equal
     * 0.3, so an invoice paid in three instalments could otherwise sit at
     * "part paid" forever with nothing visibly outstanding.
     */
    public static function for(float $total, float $paid): self
    {
        $outstanding = round($total - $paid, 2);

        return match (true) {
            $outstanding <= 0.0 => self::Paid,
            $paid > 0.0 => self::PartiallyPaid,
            default => self::Unpaid,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
