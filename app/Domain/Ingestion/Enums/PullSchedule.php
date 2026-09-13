<?php

namespace App\Domain\Ingestion\Enums;

use Illuminate\Support\Carbon;

/**
 * How often we go and look.
 *
 * A fixed set rather than a cron expression. A cron field on a settings screen
 * is a field people get wrong in ways that are invisible until the sync has not
 * run for a week, and "every fifteen minutes" covers what anybody actually
 * wants from a CRM integration.
 */
enum PullSchedule: string
{
    case Manual = 'manual';
    case QuarterHourly = 'quarter_hourly';
    case Hourly = 'hourly';
    case Daily = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Only when I press Sync now',
            self::QuarterHourly => 'Every 15 minutes',
            self::Hourly => 'Every hour',
            self::Daily => 'Once a day',
        };
    }

    public function minutes(): ?int
    {
        return match ($this) {
            self::Manual => null,
            self::QuarterHourly => 15,
            self::Hourly => 60,
            self::Daily => 1440,
        };
    }

    /**
     * Whether enough time has passed since the last run.
     *
     * Measured from when the last run **happened**, not from a fixed clock
     * slot: a sync that overran should not be immediately followed by another,
     * and a source added at twenty past the hour should not wait forty minutes
     * for its first run.
     */
    public function isDue(?Carbon $lastRun): bool
    {
        $minutes = $this->minutes();

        if ($minutes === null) {
            return false;
        }

        return $lastRun === null || $lastRun->addMinutes($minutes)->isPast();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
