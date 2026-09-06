<?php

namespace App\Domain\Deals\Enums;

/**
 * What reaching a stage means for the deal.
 *
 * Won and lost are properties of a *stage* rather than a separate flag on the
 * deal, so a board column and a closed outcome are the same thing and cannot
 * disagree — a deal sitting in "Closed won" is won by definition.
 */
enum StageOutcome: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Still open',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'The deal is still being worked.',
            self::Won => 'Reaching this stage closes the deal as won.',
            self::Lost => 'Reaching this stage closes the deal as lost.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'slate',
            self::Won => 'emerald',
            self::Lost => 'rose',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }

    /**
     * The probability a stage of this outcome is pinned to, or null when the
     * stage is free to carry its own.
     *
     * A won stage is certain and a lost one is not happening; letting an
     * administrator type 60% against "Closed won" would make every forecast
     * wrong in a way nobody would think to look for.
     */
    public function fixedProbability(): ?int
    {
        return match ($this) {
            self::Open => null,
            self::Won => 100,
            self::Lost => 0,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $outcome) {
            $options[$outcome->value] = $outcome->label();
        }

        return $options;
    }
}
