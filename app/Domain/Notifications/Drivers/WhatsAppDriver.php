<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Messaging\MessagingConfiguration;
use App\Domain\Messaging\MessagingProviders;
use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use RuntimeException;

/**
 * WhatsApp Business delivery.
 *
 * Same shape as SMS, and deliberately so — but worth knowing that WhatsApp is
 * not simply "SMS with a different bill". Outside a 24-hour window from the
 * customer's last message, the provider will only accept a template the
 * platform has approved, and a free-form notification is refused. The refusal
 * is surfaced in the provider's own words and lands in the notification log;
 * pre-approved templates are a later piece of work.
 */
class WhatsAppDriver implements ChannelDriver
{
    public function __construct(private readonly MessagingConfiguration $configuration) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::WhatsApp;
    }

    public function isConfigured(): bool
    {
        return $this->configuration->isConfigured(MessagingProviders::WHATSAPP);
    }

    public function unavailableReason(): ?string
    {
        return $this->configuration->unavailableReason(MessagingProviders::WHATSAPP);
    }

    public function send(NotificationMessage $message): void
    {
        $number = $message->destination();

        if ($number === null || $number === '') {
            throw new RuntimeException('No phone number for this recipient.');
        }

        $this->configuration->send(MessagingProviders::WHATSAPP, $number, $message->body);
    }
}
