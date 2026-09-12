<?php

namespace App\Domain\Sales\Pricing;

/**
 * What one line comes to, broken down.
 *
 * Every figure is already rounded to the penny. That is deliberate and it is
 * the whole reason this is a value object rather than a handful of floats
 * passed around: the numbers a document **prints** are these, and the document
 * total is their sum. A total computed from unrounded intermediates would be a
 * figure that does not equal the sum of the visible lines, and a document that
 * disagrees with itself is one somebody disputes.
 */
readonly class LineTotal
{
    public function __construct(
        /** Quantity × unit price, before anything is taken off. */
        public float $gross,
        /** What the discount took off. */
        public float $discount,
        /** After the discount, before tax. */
        public float $net,
        public float $tax,
        /** What the customer pays for this line. */
        public float $total,
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0, 0.0);
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
     * The columns this writes on a line row.
     *
     * `gross` and `discount` are not stored: both are derivable from the
     * quantity, the unit price and the discount the line already keeps, and a
     * fourth stored copy of the same arithmetic is a fourth thing that can
     * disagree with the others.
     *
     * @return array<string, float>
     */
    public function toAttributes(): array
    {
        return [
            'net_total' => $this->net,
            'tax_total' => $this->tax,
            'line_total' => $this->total,
        ];
    }
}
