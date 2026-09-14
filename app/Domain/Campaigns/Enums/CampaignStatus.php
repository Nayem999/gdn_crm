<?php

namespace App\Domain\Campaigns\Enums;

/**
 * Where a campaign is in its own life.
 *
 * Deliberately about the campaign and not about its results: "did it work?" is
 * a question for the figures, and a status that tried to answer it would have
 * to be recalculated every time a deal closed.
 */
enum CampaignStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'slate',
            self::Active => 'emerald',
            self::Paused => 'amber',
            self::Completed => 'blue',
            self::Cancelled => 'rose',
        };
    }

    /**
     * Whether money can still be spent against it.
     *
     * What the Meta synchronisation reads to decide whether a linked Meta
     * campaign's spend is still expected to move, and what the dashboard reads
     * to count "active campaigns".
     */
    public function isRunning(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the campaign is over, however it ended. A completed campaign's
     * cost per lead is final; a cancelled one's is a warning.
     */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
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
