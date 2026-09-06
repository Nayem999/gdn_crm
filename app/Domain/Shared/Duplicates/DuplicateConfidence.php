<?php

namespace App\Domain\Shared\Duplicates;

/**
 * How convincing a duplicate match is, as a band.
 *
 * The point of the bands is that only a human decides to merge: an "exact"
 * match on email is shown as such, but nothing merges itself.
 */
enum DuplicateConfidence: string
{
    case Exact = 'exact';
    case Strong = 'strong';
    case Possible = 'possible';

    /**
     * Below this a match is not worth reporting: a shared company name alone is
     * noise on any list of more than a handful of records.
     */
    public const FLOOR = 30;

    public function threshold(): int
    {
        return match ($this) {
            self::Exact => 100,
            self::Strong => 70,
            self::Possible => self::FLOOR,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Almost certainly the same',
            self::Strong => 'Probably the same',
            self::Possible => 'Possibly related',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Exact => 'Exact',
            self::Strong => 'Likely',
            self::Possible => 'Possible',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Exact => 'rose',
            self::Strong => 'amber',
            self::Possible => 'slate',
        };
    }

    /**
     * The band a score falls in, or null when it is below the reporting floor.
     */
    public static function forScore(int $score): ?self
    {
        foreach (self::descending() as $confidence) {
            if ($score >= $confidence->threshold()) {
                return $confidence;
            }
        }

        return null;
    }

    /**
     * @return array<int, self>
     */
    public static function descending(): array
    {
        return [self::Exact, self::Strong, self::Possible];
    }
}
