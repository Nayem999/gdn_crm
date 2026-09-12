<?php

namespace App\Domain\Sales\Contracts;

use App\Domain\Products\Models\PriceBook;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Sales\Pricing\DocumentTotals;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something made of lines that adds up to a price.
 *
 * A quote today; an order and an invoice in 6.4 and 6.5. The contract exists so
 * the line-saving action can be told what it is working with rather than
 * guessing with `method_exists` — which compiles, runs, and silently does the
 * wrong thing the first time a document is passed that happens to lack one of
 * the methods.
 *
 * `HasDocumentLines` satisfies all of it except `taxMode()`, which a document
 * that stores the choice overrides.
 */
interface SellingDocument
{
    /**
     * @return MorphMany<DocumentLine, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function lines(): MorphMany;

    /**
     * Whether the prices on this document already include tax.
     */
    public function taxMode(): TaxMode;

    /**
     * The book this document prices from, or null for the catalogue.
     *
     * Named apart from the `priceBook` relation so a document that has no such
     * relation — an invoice copied from an order, say — can answer the question
     * without pretending to have one.
     */
    public function documentPriceBook(): ?PriceBook;

    public function totals(): DocumentTotals;
}
