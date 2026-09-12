<?php

namespace App\Mail;

use App\Domain\Sales\Documents\QuotePdf;
use App\Domain\Sales\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A quote, on its way to the customer, with the PDF attached.
 *
 * The PDF is generated **when the mail is built**, inside the queued job, and
 * not carried through the queue as bytes: a serialised attachment makes the job
 * payload the size of the document, and a hundred queued quotes would be a
 * hundred PDFs sitting in Redis.
 */
class QuoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Quote $quote,
        /** $subject and $body are declared by Mailable, so they are assigned. */
        string $subject,
        public readonly string $intro,
    ) {
        $this->subject = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quote', with: [
            'quote' => $this->quote,
            'intro' => $this->intro,
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(QuotePdf::class);

        return [
            Attachment::fromData(
                fn (): string => $pdf->render($this->quote),
                $pdf->filename($this->quote),
            )->withMime('application/pdf'),
        ];
    }
}
