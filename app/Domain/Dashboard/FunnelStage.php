<?php

namespace App\Domain\Dashboard;

use App\Domain\Deals\Enums\StageOutcome;

/**
 * One band of the pipeline funnel.
 */
final readonly class FunnelStage
{
    public function __construct(
        public string $key,
        public string $name,
        public int $count,
        public float $value,
        public int $probability,
        public StageOutcome $outcome,
        /**
         * This band's width as a percentage of the widest one, 0–100.
         *
         * Relative to the **largest** stage rather than to the total: a funnel
         * drawn against the total is unreadable once there are eight stages,
         * because every band is a sliver. The count is printed beside it either
         * way, so the bar is a shape, not the figure.
         */
        public float $share,
    ) {}

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    public function color(): string
    {
        return match ($this->outcome) {
            StageOutcome::Won => 'emerald',
            StageOutcome::Lost => 'rose',
            StageOutcome::Open => 'indigo',
        };
    }
}
