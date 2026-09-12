<?php

namespace App\Mail;

use App\Domain\Mail\Templates\RenderedEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A rendered template on its way out.
 *
 * The tracking id rides in a header rather than being looked up afterwards:
 * the body already contains it, and the delivery log has to store the same one
 * or the pixel reports an open against nothing.
 */
class TemplatedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public const TRACKING_HEADER = 'X-CRM-Tracking-Id';

    public function __construct(public readonly RenderedEmail $rendered) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered->subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->rendered->trackingId === null
            ? []
            : [self::TRACKING_HEADER => $this->rendered->trackingId]);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->rendered->html);
    }
}
