<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Mail\MailConfiguration;
use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends through whatever mailer the application is configured with.
 *
 * "Configured" is the email provider's own answer, not the presence of a
 * config key: an SMTP provider with no host is configured as far as Laravel is
 * concerned and cannot send a thing. Asking the provider means the notification
 * log says "Mailgun needs a sending domain" rather than failing per message
 * with a connection error.
 */
class MailDriver implements ChannelDriver
{
    public function __construct(private readonly MailConfiguration $configuration) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function isConfigured(): bool
    {
        return $this->configuration->isConfigured();
    }

    public function unavailableReason(): ?string
    {
        return $this->configuration->unavailableReason();
    }

    public function send(NotificationMessage $message): void
    {
        $address = $message->destination();

        if ($address === null || $address === '') {
            throw new RuntimeException('No email address for this recipient.');
        }

        Mail::to($address)->send(new NotificationMail(
            subject: $message->subject ?: config('app.name').' notification',
            body: $message->body,
            url: $message->url,
        ));
    }
}
