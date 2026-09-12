<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raises the next version of a quote.
 *
 * A **new row**, not an edit. The customer holds a copy of what they were sent,
 * and the one thing a disputed quote must not be is a reconstruction — so the
 * old version stays exactly as it was and is marked superseded, while the new
 * one starts as a draft carrying the same lines.
 *
 * Both halves in one transaction: a moment where the old version is superseded
 * and the new one does not exist yet is a moment where the customer's quote has
 * no live counterpart here.
 */
class ReviseQuoteAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $changeStatus) {}

    /**
     * @throws RuntimeException when this quote is not one that can be revised
     */
    public function __invoke(Quote $quote): Quote
    {
        if (! $quote->status()->canBeRevised()) {
            throw new RuntimeException(
                $quote->status() === QuoteStatus::Draft
                    ? 'This quote is still a draft — edit it rather than revising it.'
                    : 'A '.strtolower($quote->status()->label()).' quote cannot be revised.'
            );
        }

        return DB::transaction(function () use ($quote): Quote {
            $rootId = $quote->rootId();

            // The highest version so far, not this one's plus one: revising an
            // older version twice must not produce two v3s, and the unique
            // index on (number, version) would refuse the second anyway.
            $nextVersion = (int) Quote::query()
                ->where('number', $quote->number)
                ->max('version') + 1;

            $copy = new Quote;

            $copy->forceFill([
                ...$quote->only([
                    'number', 'account_id', 'contact_id', 'deal_id',
                    'bill_to_name', 'bill_to_address', 'bill_to_email',
                    'owner_id', 'tax_mode', 'price_book_id',
                    'intro', 'terms', 'notes',
                ]),
                'version' => $nextVersion,
                'root_id' => $rootId,
                'status' => QuoteStatus::Draft->value,
                // A new version is a new offer, so it is dated today and its
                // own clock starts now. Carrying the old validity forward would
                // hand the customer a quote that was already expired.
                'issue_date' => now()->toDateString(),
                'valid_until' => $quote->valid_until === null
                    ? null
                    : now()->addDays(max(1, (int) $quote->issue_date->diffInDays($quote->valid_until)))->toDateString(),
                'subtotal' => $quote->subtotal,
                'discount_total' => $quote->discount_total,
                'tax_total' => $quote->tax_total,
                'total' => $quote->total,
            ])->save();

            $this->copyLines($quote, $copy);

            // Through the action, so the rules and the stamp are the same ones
            // every other move uses.
            $this->changeStatus->__invoke($quote, QuoteStatus::Superseded);

            return $copy->fresh() ?? $copy;
        });
    }

    private function copyLines(Quote $from, Quote $to): void
    {
        foreach ($from->lines()->get() as $line) {
            $copy = $line->replicate(['document_type', 'document_id']);

            $copy->forceFill([
                'document_type' => $to->getMorphClass(),
                'document_id' => $to->id,
            ])->save();
        }
    }
}
