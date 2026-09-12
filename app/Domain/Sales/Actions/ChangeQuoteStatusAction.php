<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only thing that moves a quote's status.
 *
 * Every route in — the screen, the send action, the expiry sweep, 6.4's
 * conversion — comes through here, so the transition rules in `QuoteStatus`
 * are enforced once. A quote that could go from accepted back to draft is not a
 * quote; it is a form somebody can rewrite after the customer agreed to it.
 *
 * The stamps are set here too, beside the move that earns them, rather than by
 * whoever happened to call: a `sent_at` written by one caller and not another
 * is a column nobody can trust.
 */
class ChangeQuoteStatusAction
{
    /**
     * @throws RuntimeException when the move is not one the current status allows
     */
    public function __invoke(Quote $quote, QuoteStatus $target, ?Carbon $at = null): Quote
    {
        $current = $quote->status();
        $at ??= Carbon::now();

        if ($current === $target) {
            return $quote;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(
                'A '.strtolower($current->label()).' quote cannot be marked '.strtolower($target->label()).'.'
            );
        }

        $quote->forceFill([
            'status' => $target->value,
            ...$this->stamp($target, $at),
        ])->save();

        return $quote->fresh() ?? $quote;
    }

    /**
     * @return array<string, mixed>
     */
    private function stamp(QuoteStatus $target, Carbon $at): array
    {
        return match ($target) {
            QuoteStatus::Sent => ['sent_at' => $at],
            QuoteStatus::Accepted => ['accepted_at' => $at],
            QuoteStatus::Declined => ['declined_at' => $at],
            QuoteStatus::Superseded => ['superseded_at' => $at],
            default => [],
        };
    }
}
