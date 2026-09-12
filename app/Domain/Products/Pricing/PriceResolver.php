<?php

namespace App\Domain\Products\Pricing;

use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\PriceBookEntry;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Carbon;

/**
 * What to charge for a product.
 *
 * **One order, in one place**, because every document that sells something asks
 * this question and they must all get the same answer:
 *
 *   1. the named book, if it applies today and prices this product
 *   2. the default book, on the same terms
 *   3. the product's own list price
 *
 * A book that does not apply — switched off, or outside its dates — is stepped
 * over rather than treated as pricing the product at nothing. That is the
 * difference between a promotion ending and every product becoming free.
 *
 * The named book falling through to the default is deliberate and worth being
 * clear about: a book is an **override list**, not a complete catalogue.
 * Somebody building a reseller book adds the twenty products that differ; the
 * other four hundred should keep their usual price rather than vanish.
 */
class PriceResolver
{
    /**
     * @param  PriceBook|null  $book  The book the document is working in.
     * @param  Carbon|null  $on  The day to price for; today when omitted.
     */
    public function priceFor(Product $product, ?PriceBook $book = null, ?Carbon $on = null): float
    {
        $on ??= Carbon::now();

        foreach ($this->booksToTry($book, $on) as $candidate) {
            $price = $this->entryPrice($product, $candidate);

            if ($price !== null) {
                return $price;
            }
        }

        return $product->listPrice();
    }

    /**
     * The same answer, and where it came from.
     *
     * A quote screen has to be able to say "reseller price" beside a figure;
     * without this it would have to re-derive the order and could disagree with
     * the figure it is labelling.
     */
    public function resolve(Product $product, ?PriceBook $book = null, ?Carbon $on = null): ResolvedPrice
    {
        $on ??= Carbon::now();

        foreach ($this->booksToTry($book, $on) as $candidate) {
            $price = $this->entryPrice($product, $candidate);

            if ($price !== null) {
                return new ResolvedPrice($price, $candidate);
            }
        }

        return new ResolvedPrice($product->listPrice(), null);
    }

    /**
     * What a line of this product costs.
     *
     * Rounded once, at the end. Rounding the unit price first and multiplying
     * turns a third of a penny into a penny per unit, which on a quantity of a
     * thousand is a figure somebody notices.
     */
    public function lineTotal(Product $product, float $quantity, ?PriceBook $book = null, ?Carbon $on = null): float
    {
        return round($this->priceFor($product, $book, $on) * $quantity, 2);
    }

    /**
     * Prices for many products at once, keyed by product id.
     *
     * A quote with thirty lines asking one at a time is thirty queries per
     * book. This asks once per book.
     *
     * @param  iterable<int, Product>  $products
     * @return array<int, float>
     */
    public function priceMap(iterable $products, ?PriceBook $book = null, ?Carbon $on = null): array
    {
        $on ??= Carbon::now();
        $catalogue = [];

        foreach ($products as $product) {
            $catalogue[$product->id] = $product->listPrice();
        }

        if ($catalogue === []) {
            return [];
        }

        // Walked in reverse, so the book earlier in the order overwrites the
        // one after it and the first match wins — the same order priceFor()
        // applies, expressed as a fill rather than a loop of lookups.
        foreach (array_reverse($this->booksToTry($book, $on)) as $candidate) {
            $entries = PriceBookEntry::query()
                ->where('price_book_id', $candidate->id)
                ->whereIn('product_id', array_keys($catalogue))
                ->pluck('price', 'product_id');

            foreach ($entries as $productId => $price) {
                $catalogue[(int) $productId] = (float) $price;
            }
        }

        return $catalogue;
    }

    /**
     * The books to consult, in order, with the ones that do not apply removed.
     *
     * The default is not consulted twice when it is also the named book.
     *
     * @return array<int, PriceBook>
     */
    private function booksToTry(?PriceBook $book, Carbon $on): array
    {
        $books = [];

        if ($book !== null && $book->appliesOn($on)) {
            $books[] = $book;
        }

        $default = PriceBook::default();

        if ($default !== null && $default->appliesOn($on) && $default->id !== $book?->id) {
            $books[] = $default;
        }

        return $books;
    }

    private function entryPrice(Product $product, PriceBook $book): ?float
    {
        $entry = PriceBookEntry::query()
            ->where('price_book_id', $book->id)
            ->where('product_id', $product->id)
            ->first();

        return $entry?->price();
    }
}
