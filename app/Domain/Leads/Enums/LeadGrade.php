<?php

namespace App\Domain\Leads\Enums;

/**
 * A lead's score as a band, so a number becomes something to act on.
 *
 * The bands are quarters of the 0-100 range. Colours follow the same warm-to-
 * cold semantics the UI standard asks for, and live here rather than in Blade.
 */
enum LeadGrade: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cool = 'cool';
    case Cold = 'cold';

    /**
     * The lowest score in this band.
     */
    public function threshold(): int
    {
        return match ($this) {
            self::Hot => 75,
            self::Warm => 50,
            self::Cool => 25,
            self::Cold => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Hot => 'Hot',
            self::Warm => 'Warm',
            self::Cool => 'Cool',
            self::Cold => 'Cold',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Hot => 'Worth a call today.',
            self::Warm => 'Worth working this week.',
            self::Cool => 'Keep in the nurture list.',
            self::Cold => 'Nothing here yet.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Hot => 'rose',
            self::Warm => 'amber',
            self::Cool => 'blue',
            self::Cold => 'slate',
        };
    }

    /**
     * The band a score falls in. Bands are checked from the top down, so the
     * ranges cannot leave a gap however the thresholds are set.
     */
    public static function forScore(int $score): self
    {
        foreach (self::descending() as $grade) {
            if ($score >= $grade->threshold()) {
                return $grade;
            }
        }

        return self::Cold;
    }

    /**
     * @return array<int, self>
     */
    public static function descending(): array
    {
        return [self::Hot, self::Warm, self::Cool, self::Cold];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::descending() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
