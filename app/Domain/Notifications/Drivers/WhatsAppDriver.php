<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Settings\SettingsRegistry;
use RuntimeException;

/**
 * WhatsApp Business delivery.
 *
 * As with SMS, the provider token, phone number id and approved template ids
 * arrive in task 7.6. Reported as unconfigured until then.
 */
class WhatsAppDriver implements ChannelDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::WhatsApp;
    }

    public function isConfigured(): bool
    {
        // As with SMS: the group is not registered until 7.6.
        return SettingsRegistry::find('whatsapp.token') !== null && settings()->isSet('whatsapp.token');
    }

    public function unavailableReason(): ?string
    {
        return $this->isConfigured() ? null : 'WhatsApp is not connected yet.';
    }

    public function send(NotificationMessage $message): void
    {
        throw new RuntimeException('WhatsApp is not connected yet.');
    }
}
