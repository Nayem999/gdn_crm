<?php

namespace App\Domain\Leads\DTOs;

use App\Domain\Leads\Enums\LeadGrade;

/**
 * What the scoring rules made of one lead.
 *
 * `points` is the raw sum, which may be negative or over 100; `score` is that
 * clamped to the 0-100 range the column and the UI use. Both are kept so the
 * detail page can explain a score that hit the ceiling.
 */
readonly class LeadScore
{
    public const MAX = 100;

    public const MIN = 0;

    /**
     * @param  array<int, array{label: string, points: int}>  $matched  The rules that fired, in rule order.
     */
    public function __construct(
        public int $points,
        public int $score,
        public LeadGrade $grade,
        public array $matched = [],
    ) {}

    /**
     * @param  array<int, array{label: string, points: int}>  $matched
     */
    public static function from(int $points, array $matched = []): self
    {
        $score = max(self::MIN, min(self::MAX, $points));

        return new self($points, $score, LeadGrade::forScore($score), $matched);
    }

    public function wasClamped(): bool
    {
        return $this->points !== $this->score;
    }
}
