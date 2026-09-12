<?php

namespace App\Domain\Sales\Pricing;

use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;

/**
 * What a whole document comes to.
 *
 * **The sum of the rounded lines**, never a recalculation from the quantities.
 * The customer checks the arithmetic by adding up the column they can see; a
 * total derived any other way is right about the money and wrong about the
 * document, which is worse.
 *
 * Tax is summed **per rate** as well as in total, because a document that mixes
 * rates has to print each band separately — a tax authority asks for the
 * twenty-per-cent figure, not the total.
 */
readonly class DocumentTotals
{
    /**
     * @param  array<string, float>  $taxByRate  Rate as a string key ("20.00") => tax.
     */
    public function __construct(
        public float $gross,
        public float $discount,
        public float $net,
        public float $tax,
        public float $total,
        public array $taxByRate = [],
        public int $lineCount = 0,
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0, 0.0, [], 0);
    }

    /**
     * @param  iterable<int, DocumentLine>  $lines
     */
    public static function for(iterable $lines, TaxMode $taxMode = TaxMode::Exclusive): self
    {
        $calculator = app(LineCalculator::class);

        $gross = 0.0;
        $discount = 0.0;
        $net = 0.0;
        $tax = 0.0;
        $total = 0.0;
        $byRate = [];
        $count = 0;

        foreach ($lines as $line) {
            $figures = $calculator->forLine($line, $taxMode);

            $gross += $figures->gross;
            $discount += $figures->discount;
            $net += $figures->net;
            $tax += $figures->tax;
            $total += $figures->total;
            $count++;

            if ($figures->tax > 0) {
                $rate = number_format((float) $line->tax_rate, 2, '.', '');
                $byRate[$rate] = round(($byRate[$rate] ?? 0) + $figures->tax, 2);
            }
        }

        // Each addend was already rounded, so these rounds only tidy the
        // accumulated binary representation — they never move a figure.
        return new self(
            gross: round($gross, 2),
            discount: round($discount, 2),
            net: round($net, 2),
            tax: round($tax, 2),
            total: round($total, 2),
            taxByRate: $byRate,
            lineCount: $count,
        );
    }

    public function hasDiscount(): bool
    {
        return $this->discount > 0;
    }

    public function hasTax(): bool
    {
        return $this->tax > 0;
    }

    /**
     * Whether more than one tax rate is in play, which is when a document has
     * to print the bands rather than one figure.
     */
    public function hasMixedTaxRates(): bool
    {
        return count($this->taxByRate) > 1;
    }

    /**
     * The columns these write on a document row.
     *
     * @return array<string, float>
     */
    public function toAttributes(): array
    {
        return [
            'subtotal' => $this->net,
            'discount_total' => $this->discount,
            'tax_total' => $this->tax,
            'total' => $this->total,
        ];
    }
}
