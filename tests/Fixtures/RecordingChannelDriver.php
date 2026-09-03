<?php

namespace Tests\Fixtures;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\NotificationMessage;
use RuntimeException;

/**
 * A driver that keeps what it was handed instead of delivering it, so a test can
 * assert on the rendered message without faking a mailer or an HTTP client.
 *
 * Set $failWith to make delivery throw, which is how the retry and failure
 * logging paths are exercised.
 */
class RecordingChannelDriver implements ChannelDriver
{
    /** @var array<int, NotificationMessage> */
    public array $sent = [];

    public function __construct(
        private readonly NotificationChannel $channel,
        public bool $configured = true,
        public ?string $failWith = null,
        public ?string $reason = 'Not configured in this test.',
    ) {}

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function unavailableReason(): ?string
    {
        return $this->configured ? null : $this->reason;
    }

    public function send(NotificationMessage $message): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->sent[] = $message;
    }

    public function bodies(): string
    {
        return implode("\n", array_map(fn (NotificationMessage $m) => $m->body, $this->sent));
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
