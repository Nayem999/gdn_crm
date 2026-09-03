<?php

namespace App\Domain\Notifications\Enums;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    /** Suppressed on purpose: muted, rate limited, or the channel is unconfigured. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'amber',
            self::Sent => 'emerald',
            self::Failed => 'rose',
            self::Skipped => 'slate',
        };
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
