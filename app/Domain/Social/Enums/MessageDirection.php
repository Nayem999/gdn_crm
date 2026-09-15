<?php

namespace App\Domain\Social\Enums;

/**
 * Who said it.
 *
 * Two cases rather than a boolean `is_inbound`, because the column is read on a
 * screen and in a query, and `direction = 'inbound'` says what it means where
 * `is_inbound = 0` needs the reader to remember which way round it was.
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Received',
            self::Outbound => 'Sent',
        };
    }

    public function isInbound(): bool
    {
        return $this === self::Inbound;
    }
}
