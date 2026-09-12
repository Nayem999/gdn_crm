<?php

namespace App\Domain\Sales\Enums;

/**
 * How a discount is expressed.
 *
 * Two cases rather than one signed number, because "10% off" and "10 off" are
 * different instructions and a printed document has to say which was meant. A
 * customer reading "Discount: 10" on a line of 500 will read it as fifty, and
 * be wrong, and ring somebody about it.
 */
enum DiscountType: string
{
    case Percentage = 'percentage';
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Amount => 'Fixed amount',
        };
    }

    /**
     * The discount on a gross line amount.
     *
     * Capped at the line itself: a fixed discount larger than what is being
     * discounted would make the line negative, which on an invoice is a credit
     * note — a different document with different rules, not a line with a minus
     * sign on it.
     */
    public function on(float $gross, float $value): float
    {
        if ($value <= 0 || $gross <= 0) {
            return 0.0;
        }

        $discount = match ($this) {
            // Clamped at 100: "110% off" is a typo, and honouring it would pay
            // the customer to take the goods.
            self::Percentage => $gross * (min($value, 100) / 100),
            self::Amount => $value,
        };

        return round(min($discount, $gross), 2);
    }

    /**
     * How it reads on a document, beside the figure.
     */
    public function suffix(): string
    {
        return $this === self::Percentage ? '%' : '';
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
