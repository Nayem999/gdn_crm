<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Pricing\PriceResolver;
use App\Domain\Sales\Contracts\SellingDocument;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Sales\Pricing\DocumentTotals;
use App\Domain\Sales\Pricing\LineCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes a document's lines, and the totals that follow from them.
 *
 * **The only writer of line figures.** Every stored `net_total`, `tax_total`
 * and `line_total` comes from `LineCalculator` here, and the document's own
 * totals come from summing those. Anywhere else computing them would be a
 * second arithmetic that nobody can see disagreeing with the first.
 *
 * Lines are **snapshotted from the product at the moment they are added**: the
 * name, unit, price and tax rate are copied. After that the line is its own
 * thing — repricing the catalogue does not reach back into a quote somebody
 * already has a copy of.
 *
 * Reconciled rather than replaced, so a line keeps its id across a save. 6.4
 * and 6.5 copy lines between documents by id, and handing them new ids on every
 * edit would break the trail from an invoice line back to the quote line it
 * came from.
 */
class SaveQuoteLinesAction
{
    public function __construct(
        private readonly LineCalculator $calculator,
        private readonly PriceResolver $prices,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines  As a form submitted them.
     */
    public function __invoke(Model&SellingDocument $document, array $lines): void
    {
        DB::transaction(function () use ($document, $lines): void {
            $kept = [];
            $position = 0;

            foreach ($lines as $submitted) {
                $line = $this->write($document, $submitted, $position);

                if ($line === null) {
                    continue;
                }

                $kept[] = $line->id;
                $position++;
            }

            DocumentLine::query()
                ->where('document_type', $document->getMorphClass())
                ->where('document_id', $document->getKey())
                ->whereNotIn('id', $kept === [] ? [0] : $kept)
                ->delete();

            $this->writeTotals($document);
        });
    }

    /**
     * Recompute and store a document's totals from whatever lines it has.
     *
     * Public because 6.4 and 6.5 copy lines directly and then need the header
     * figures brought into line with them.
     */
    public function writeTotals(Model&SellingDocument $document): void
    {
        $lines = DocumentLine::query()
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->get();

        $taxMode = method_exists($document, 'taxMode')
            ? $document->taxMode()
            : TaxMode::Exclusive;

        $document->forceFill(DocumentTotals::for($lines, $taxMode)->toAttributes())->save();
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private function write(Model&SellingDocument $document, array $submitted, int $position): ?DocumentLine
    {
        $product = $this->product($submitted);
        $quantity = round((float) ($submitted['quantity'] ?? 0), 3);

        // A row somebody started and abandoned: no product and no name means
        // there is nothing to print.
        $name = trim((string) ($submitted['name'] ?? ($product === null ? '' : $product->name)));

        if ($name === '' || $quantity <= 0) {
            return null;
        }

        $line = $this->existing($document, $submitted) ?? new DocumentLine([
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
        ]);

        $discountType = DiscountType::tryFrom((string) ($submitted['discount_type'] ?? ''));
        $unitPrice = $this->unitPrice($submitted, $product, $document);
        $taxRate = $this->taxRate($submitted, $product);

        $figures = $this->calculator->total(
            quantity: $quantity,
            unitPrice: $unitPrice,
            discountType: $discountType,
            discountValue: round((float) ($submitted['discount_value'] ?? 0), 2),
            taxRate: $taxRate,
            taxMode: method_exists($document, 'taxMode')
                ? $document->taxMode()
                : TaxMode::Exclusive,
        );

        $line->forceFill([
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
            'product_id' => $product?->id,
            'position' => $position,
            'name' => $name,
            'description' => trim((string) ($submitted['description'] ?? '')) ?: null,
            'unit' => ($submitted['unit'] ?? null) ?: ($product === null ? 'each' : $product->unit()->value),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_type' => $discountType?->value,
            'discount_value' => round((float) ($submitted['discount_value'] ?? 0), 2),
            'tax_rate' => $taxRate,
            ...$figures->toAttributes(),
        ])->save();

        return $line;
    }

    /**
     * The line being edited, but only if it belongs to this document.
     *
     * An id from elsewhere would otherwise move somebody else's line onto this
     * quote.
     *
     * @param  array<string, mixed>  $submitted
     */
    private function existing(Model&SellingDocument $document, array $submitted): ?DocumentLine
    {
        $id = (int) ($submitted['id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        return DocumentLine::query()
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', $document->getKey())
            ->whereKey($id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private function product(array $submitted): ?Product
    {
        $id = (int) ($submitted['product_id'] ?? 0);

        return $id > 0 ? Product::query()->whereKey($id)->first() : null;
    }

    /**
     * What to charge.
     *
     * A price typed on the line wins — quoting means being able to agree a
     * figure. Otherwise it is resolved through the price book the document is
     * working in, which is the same answer the catalogue screen shows.
     *
     * @param  array<string, mixed>  $submitted
     */
    private function unitPrice(array $submitted, ?Product $product, Model&SellingDocument $document): float
    {
        $typed = $submitted['unit_price'] ?? null;

        if ($typed !== null && $typed !== '') {
            return round((float) $typed, 2);
        }

        if ($product === null) {
            return 0.0;
        }

        return $this->prices->priceFor($product, $document->documentPriceBook());
    }

    /**
     * @param  array<string, mixed>  $submitted
     */
    private function taxRate(array $submitted, ?Product $product): float
    {
        $typed = $submitted['tax_rate'] ?? null;

        if ($typed !== null && $typed !== '') {
            return round((float) $typed, 2);
        }

        return $product === null ? 0.0 : round((float) $product->tax_rate, 2);
    }
}
