<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Messaging\MessagingConfiguration;
use App\Domain\Messaging\MessagingProviders;
use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use RuntimeException;

/**
 * SMS delivery, through whichever provider the settings name.
 *
 * The driver knows nothing about Twilio or Vonage: it asks the configuration
 * for the active provider and hands over a number and some words. That is what
 * makes "change the SMS account" a form rather than a deploy.
 */
class SmsDriver implements ChannelDriver
{
    public function __construct(private readonly MessagingConfiguration $configuration) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function isConfigured(): bool
    {
        return $this->configuration->isConfigured(MessagingProviders::SMS);
    }

    public function unavailableReason(): ?string
    {
        return $this->configuration->unavailableReason(MessagingProviders::SMS);
    }

    public function send(NotificationMessage $message): void
    {
        $number = $message->destination();

        if ($number === null || $number === '') {
            throw new RuntimeException('No phone number for this recipient.');
        }

        // The subject is not sent: an SMS has no subject line, and prefixing one
        // would spend a third of the message on a repeat of the first sentence.
        $this->configuration->send(MessagingProviders::SMS, $number, $message->body);
    }
}
