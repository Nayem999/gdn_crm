<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Drivers\InAppDriver;
use App\Domain\Notifications\Drivers\MailDriver;
use App\Domain\Notifications\Drivers\SmsDriver;
use App\Domain\Notifications\Drivers\WhatsAppDriver;
use App\Domain\Notifications\Enums\NotificationChannel;

/**
 * Hands out the driver for a channel.
 *
 * Drivers can be swapped at runtime, which is how tests substitute a recording
 * driver without faking the queue or the mailer.
 */
class ChannelManager
{
    /**
     * @var array<string, ChannelDriver>
     */
    private array $drivers = [];

    public function driver(NotificationChannel $channel): ChannelDriver
    {
        return $this->drivers[$channel->value] ??= $this->make($channel);
    }

    public function extend(NotificationChannel $channel, ChannelDriver $driver): void
    {
        $this->drivers[$channel->value] = $driver;
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function configuredChannels(): array
    {
        return array_values(array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel) => $this->driver($channel)->isConfigured()
        ));
    }

    private function make(NotificationChannel $channel): ChannelDriver
    {
        return match ($channel) {
            NotificationChannel::InApp => app(InAppDriver::class),
            NotificationChannel::Email => app(MailDriver::class),
            NotificationChannel::Sms => app(SmsDriver::class),
            NotificationChannel::WhatsApp => app(WhatsAppDriver::class),
        };
    }
}
