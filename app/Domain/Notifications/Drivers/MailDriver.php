<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sends through whatever mailer the application is configured with.
 *
 * Phase 7.1 replaces the transport underneath with the settings-driven
 * multi-provider mailer; this driver keeps the same contract, so nothing that
 * dispatches a notification has to change.
 */
class MailDriver implements ChannelDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function isConfigured(): bool
    {
        return config('mail.default') !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->isConfigured() ? null : 'No mailer is configured.';
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
