<?php

namespace App\Domain\Products\Pricing;

use App\Domain\Products\Models\PriceBook;

/**
 * A price, and where it came from.
 *
 * The source travels with the figure rather than being worked out again by
 * whatever displays it: a screen labelling a price "reseller" by re-deriving
 * the order could label a figure it did not produce.
 */
readonly class ResolvedPrice
{
    public function __construct(
        public float $amount,
        /** Null when the catalogue's own list price was used. */
        public ?PriceBook $book = null,
        /** The threshold of the quantity break that produced this, if one did. */
        public ?float $quantityBreakAt = null,
    ) {}

    public function fromCatalogue(): bool
    {
        return $this->book === null;
    }

    public function fromQuantityBreak(): bool
    {
        return $this->quantityBreakAt !== null;
    }

    public function sourceLabel(): string
    {
        $source = $this->book === null ? 'Catalogue price' : $this->book->name;

        return $this->quantityBreakAt === null
            ? $source
            : $source.' — '.rtrim(rtrim(number_format($this->quantityBreakAt, 3, '.', ''), '0'), '.').'+';
    }
}
