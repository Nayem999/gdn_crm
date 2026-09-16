<?php

namespace App\Domain\Meta\Conversions\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    /**
     * Never attempted, and never will be: the record carried nothing Meta could
     * match a person by. Distinct from failed, because there is nothing to fix
     * and retrying it would only fail again.
     */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to send',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Not sendable',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Sent => 'emerald',
            self::Failed => 'red',
            self::Skipped => 'slate',
        };
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
