<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A rendered notification as an email.
 *
 * The body is admin-authored plain text already merged by TemplateRenderer, and
 * the view escapes it — a template is never treated as markup or as Blade.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $subject is declared by Mailable itself, so it is assigned rather than
     * promoted — a promoted readonly property cannot redeclare an inherited one.
     */
    public function __construct(
        string $subject,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {
        $this->subject = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notification', with: [
            'body' => $this->body,
            'url' => $this->url,
        ]);
    }
}
