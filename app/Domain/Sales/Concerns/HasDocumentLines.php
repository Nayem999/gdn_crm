<?php

namespace App\Domain\Sales\Concerns;

use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Sales\Pricing\DocumentTotals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A document made of lines.
 *
 * Used by quotes, orders and invoices. What it gives them is not just the
 * relation but the **single way of totalling**: a document's figures are the
 * sum of its rounded lines, computed by one class, so the three document types
 * cannot arrive at different answers for the same lines.
 *
 * @phpstan-require-extends Model
 */
trait HasDocumentLines
{
    /**
     * @return MorphMany<DocumentLine, $this>
     */
    public function lines(): MorphMany
    {
        return $this->morphMany(DocumentLine::class, 'document')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Whether this document's prices already include tax.
     *
     * A document decides, not the installation: the same company routinely
     * sends inclusive documents to consumers and exclusive ones to businesses.
     * Overridden by a document that stores the choice.
     */
    public function taxMode(): TaxMode
    {
        return TaxMode::tryFrom((string) $this->getAttributeValue('tax_mode')) ?? TaxMode::Exclusive;
    }

    /**
     * What this document comes to.
     *
     * Reads the loaded relation when there is one, so a list of documents that
     * eager-loaded their lines does not query again per row.
     */
    public function totals(): DocumentTotals
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return DocumentTotals::for($lines, $this->taxMode());
    }
}
