<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Settings\SettingsRegistry;
use RuntimeException;

/**
 * SMS delivery.
 *
 * Task 7.6 supplies the provider drivers (Twilio, Vonage, a local gateway) and
 * the credentials behind them. Until then this reports itself unconfigured, so
 * the engine skips it and says why in the log rather than pretending to send.
 * Writing a stand-in provider call now would only invent an API 7.6 replaces.
 */
class SmsDriver implements ChannelDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function isConfigured(): bool
    {
        // The group is not in SettingsRegistry until 7.6, and asking the manager
        // for an unregistered group would cache an empty entry for it.
        return SettingsRegistry::find('sms.provider') !== null && settings()->isSet('sms.provider');
    }

    public function unavailableReason(): ?string
    {
        return $this->isConfigured() ? null : 'No SMS provider is configured yet.';
    }

    public function send(NotificationMessage $message): void
    {
        throw new RuntimeException('No SMS provider is configured yet.');
    }
}
