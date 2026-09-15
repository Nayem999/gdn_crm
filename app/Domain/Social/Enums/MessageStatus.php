<?php

namespace App\Domain\Social\Enums;

/**
 * Where an outbound message got to.
 *
 * Meta reports delivery as a series of separate webhooks — sent, then delivered,
 * then read — so this only ever moves forward, and 12.10's receipt handling
 * depends on that: a `delivered` webhook arriving after a `read` one (they do
 * overtake each other) must not move the message backwards.
 *
 * `Received` is the inbound resting state. An inbound message has no delivery
 * story of its own — it is here, which is the whole of what is known about it.
 */
enum MessageStatus: string
{
    case Received = 'received';
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Pending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Read',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received => 'slate',
            self::Pending => 'amber',
            self::Sent, self::Delivered => 'blue',
            self::Read => 'emerald',
            self::Failed => 'rose',
        };
    }

    /**
     * How far along this is, for deciding whether a late webhook is news.
     *
     * Meta's receipts overtake each other, so a status is only written when it
     * is further on than the one already stored.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Failed => -1,
            self::Received => 0,
            self::Pending => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
        };
    }

    /**
     * Whether moving to this status from the current one is forward progress.
     *
     * A failure always wins: it is the one thing that can happen after a send
     * and has to be visible even if a stale receipt arrives afterwards.
     */
    public function isProgressFrom(self $current): bool
    {
        return $this === self::Failed || $this->rank() > $current->rank();
    }

    public static function fromMeta(?string $status): ?self
    {
        return match ($status) {
            'sent' => self::Sent,
            'delivered' => self::Delivered,
            'read' => self::Read,
            'failed' => self::Failed,
            default => null,
        };
    }
}
