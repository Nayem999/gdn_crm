<?php

namespace App\Domain\Sales\Pricing;

use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;

/**
 * What a line comes to.
 *
 * **The only arithmetic**, so a quote, an order and an invoice cannot disagree
 * about the same line — and so the figure on screen, the figure in the PDF and
 * the figure in a report are one calculation rather than three.
 *
 * The order is fixed and it matters:
 *
 *   1. gross = quantity × unit price
 *   2. discount comes off the gross
 *   3. tax applies to what is left
 *
 * Discounting before tax rather than after is not a preference: tax is owed on
 * what was actually charged. Taxing first and then discounting the total
 * collects tax on money nobody paid.
 *
 * **Rounding happens once per line, at the end of each step**, and the document
 * total is the sum of those rounded lines. The alternative — carrying full
 * precision through and rounding the total — produces a document whose total is
 * a penny away from the sum of its own printed rows. The customer adds the
 * column up.
 */
class LineCalculator
{
    /**
     * @param  float  $quantity  May be fractional: half a day of setup is a line.
     * @param  float  $discountValue  Read according to $discountType.
     * @param  float  $taxRate  Per cent.
     */
    public function total(
        float $quantity,
        float $unitPrice,
        ?DiscountType $discountType = null,
        float $discountValue = 0,
        float $taxRate = 0,
        TaxMode $taxMode = TaxMode::Exclusive,
    ): LineTotal {
        // A negative quantity or price is not a line; it is a credit note,
        // which is a different document with its own rules.
        if ($quantity <= 0 || $unitPrice < 0) {
            return LineTotal::zero();
        }

        $gross = round($quantity * $unitPrice, 2);

        $discount = $discountType === null
            ? 0.0
            : $discountType->on($gross, $discountValue);

        $discounted = round($gross - $discount, 2);

        // Under inclusive tax the discounted figure already contains the tax,
        // so the split pulls the net back out of it; under exclusive it is the
        // net and the tax goes on top. Either way the three figures agree with
        // each other by construction.
        $split = $taxMode->split($discounted, $taxRate);

        return new LineTotal(
            gross: $gross,
            discount: $discount,
            net: $split['net'],
            tax: $split['tax'],
            total: $split['gross'],
        );
    }

    /**
     * The same answer for a stored line.
     */
    public function forLine(DocumentLine $line, TaxMode $taxMode = TaxMode::Exclusive): LineTotal
    {
        return $this->total(
            quantity: $line->quantity(),
            unitPrice: (float) $line->unit_price,
            discountType: $line->discountType(),
            discountValue: (float) $line->discount_value,
            taxRate: (float) $line->tax_rate,
            taxMode: $taxMode,
        );
    }
}
