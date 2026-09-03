<?php

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use App\Notifications\InAppNotification;
use RuntimeException;

/**
 * Writes into Laravel's own notifications table, which is what the bell reads.
 */
class InAppDriver implements ChannelDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::InApp;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function send(NotificationMessage $message): void
    {
        if ($message->user === null) {
            throw new RuntimeException('An in-app notification needs a user to belong to.');
        }

        $message->user->notify(new InAppNotification(
            event: $message->event,
            subject: $message->subject ?? '',
            body: $message->body,
            url: $message->url,
            data: $message->data,
        ));
    }
}
