<?php

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;

/**
 * One way of getting a notification to a person.
 *
 * A driver either delivers or throws. It never decides whether a message
 * *should* be sent — the matrix, the recipient's own preferences, quiet hours
 * and the throttle have all been applied before it is called.
 */
interface ChannelDriver
{
    public function channel(): NotificationChannel;

    /**
     * Whether this channel can actually reach anyone yet. An unconfigured
     * channel is skipped and logged rather than attempted and failed.
     */
    public function isConfigured(): bool;

    /**
     * Why it is not configured, for the log and the settings screen.
     */
    public function unavailableReason(): ?string;

    /**
     * @throws \RuntimeException when delivery fails.
     */
    public function send(NotificationMessage $message): void;
}
