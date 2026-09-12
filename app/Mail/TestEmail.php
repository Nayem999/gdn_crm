<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The message behind "Send test email".
 *
 * It names the provider it went through, because the whole point of receiving
 * it is knowing *which* configuration works — an administrator switching from
 * one provider to another otherwise cannot tell the new one's message from the
 * old one's.
 */
class TestEmail extends Mailable
{
    public function __construct(
        public readonly string $providerLabel,
        public readonly string $sentBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' test message');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test', with: [
            'providerLabel' => $this->providerLabel,
            'sentBy' => $this->sentBy,
        ]);
    }
}
