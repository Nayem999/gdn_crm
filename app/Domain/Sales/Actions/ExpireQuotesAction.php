<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use Illuminate\Support\Carbon;

/**
 * Marks the sent quotes whose validity has run out.
 *
 * Swept rather than computed on read, because the status is what everything
 * else keys off: a list filtered to "sent" must not include quotes that lapsed
 * a month ago, and 6.4 must not build an order from one.
 *
 * Only sent quotes lapse. A draft nobody sent has not expired, and an accepted
 * one is settled — which is why this goes through the status action rather than
 * updating the column, so those rules are the same ones everything else obeys.
 */
class ExpireQuotesAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $changeStatus) {}

    /**
     * @return int how many lapsed
     */
    public function __invoke(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $lapsed = Quote::query()
            ->where('status', QuoteStatus::Sent->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $now->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($lapsed as $quote) {
            $this->changeStatus->__invoke($quote, QuoteStatus::Expired, $now);
        }

        return $lapsed->count();
    }
}
