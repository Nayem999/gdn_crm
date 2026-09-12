<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Mail\QuoteMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends a quote to the customer and marks it sent.
 *
 * The status moves **after** the mail is queued, not before: a quote marked
 * sent that was never sent is the worse of the two failures, because nobody
 * looks for it again. Queueing is what can fail here — the send itself happens
 * later, in the job, and a bounce is the notification log's business.
 *
 * Sending locks the quote: a sent quote is not editable, because the copy the
 * customer holds and the copy here would otherwise be different documents with
 * the same number. Revising raises a new version instead.
 */
class SendQuoteAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $changeStatus) {}

    /**
     * @throws RuntimeException when the quote has nowhere to go, or cannot be sent
     */
    public function __invoke(Quote $quote, ?string $subject = null, string $intro = ''): Quote
    {
        if ($quote->status() !== QuoteStatus::Draft) {
            throw new RuntimeException('Only a draft can be sent. Revise this quote to send a new version.');
        }

        $to = trim((string) $quote->bill_to_email);

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('This quote has no email address to send to.');
        }

        if ($quote->lines()->doesntExist()) {
            // An empty quote is a draft somebody has not finished, and sending
            // one is a phone call from the customer asking what it is.
            throw new RuntimeException('This quote has no lines on it yet.');
        }

        Mail::to($to)->queue(new QuoteMail(
            $quote,
            $subject ?: $quote->reference().' from '.config('app.name'),
            $intro,
        ));

        return $this->changeStatus->__invoke($quote, QuoteStatus::Sent);
    }
}
