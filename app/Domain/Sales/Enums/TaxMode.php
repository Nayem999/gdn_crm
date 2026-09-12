<?php

namespace App\Domain\Sales\Enums;

/**
 * Whether the prices on a document already include tax.
 *
 * Both exist because both are normal, and which one an installation uses is a
 * matter of where it trades: a UK or EU business quotes VAT-inclusive to
 * consumers and exclusive to businesses, and a US business quotes exclusive and
 * adds sales tax. Getting this wrong is not a rounding difference — it is the
 * whole tax amount, on every line.
 *
 * The mode belongs to the **document**, not to the installation, because the
 * same company routinely sends both.
 */
enum TaxMode: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';

    public function label(): string
    {
        return match ($this) {
            self::Exclusive => 'Tax added on top',
            self::Inclusive => 'Tax included in the prices',
        };
    }

    /**
     * The tax on a net amount, and what that net amount really is.
     *
     * Exclusive: the figure is the net, and tax is added — 100 at 20% is 100
     * net, 20 tax, 120 total.
     *
     * Inclusive: the figure already contains the tax, so the net is what is
     * left after taking it out — 120 at 20% is 100 net, 20 tax, 120 total.
     * Dividing by 1.2 rather than taking 20% of 120, which would give 24 and a
     * total of 144: the mistake that makes every inclusive document wrong by a
     * consistent, plausible-looking amount.
     *
     * @return array{net: float, tax: float, gross: float}
     */
    public function split(float $amount, float $ratePercent): array
    {
        $rate = max(0.0, $ratePercent) / 100;

        if ($rate === 0.0) {
            return ['net' => round($amount, 2), 'tax' => 0.0, 'gross' => round($amount, 2)];
        }

        if ($this === self::Exclusive) {
            $net = round($amount, 2);
            $tax = round($amount * $rate, 2);

            return ['net' => $net, 'tax' => $tax, 'gross' => round($net + $tax, 2)];
        }

        $gross = round($amount, 2);
        $net = round($amount / (1 + $rate), 2);

        // The tax is the remainder rather than a second rounding of its own, so
        // net + tax always equals the figure that was quoted. Computing both
        // independently leaves documents that are a penny out from themselves.
        return ['net' => $net, 'tax' => round($gross - $net, 2), 'gross' => $gross];
    }

    public function includesTax(): bool
    {
        return $this === self::Inclusive;
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
