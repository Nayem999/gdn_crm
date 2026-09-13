<?php

namespace App\Domain\Reports\Forecasting;

/**
 * One month's forecast, with the numbers kept apart.
 *
 * Four figures rather than one, because they answer different questions and
 * blending them would hide which to believe:
 *
 *   - **committed** — already won in this month. A fact.
 *   - **weighted** — open deals at their stage's probability. A judgement.
 *   - **bestCase** — committed plus every open deal landing. A ceiling.
 *   - **historical** — the average month lately. What usually happens.
 */
readonly class Forecast
{
    public function __construct(
        public ForecastPeriod $period,
        public float $committed,
        public float $weighted,
        public float $bestCase,
        public float $historical,
        public int $openCount,
    ) {}

    /**
     * The number to plan on: what is already won plus what the pipeline
     * suggests will follow.
     */
    public function expected(): float
    {
        return round($this->committed + $this->weighted, 2);
    }

    /**
     * How far the expected figure sits above or below a typical month, as a
     * percentage, or null when there is no history to compare against.
     *
     * The gap is the most useful thing on the screen: an expected figure far
     * above the historical average is a pipeline that is not going to land.
     */
    public function versusHistory(): ?float
    {
        if ($this->historical <= 0) {
            return null;
        }

        return round(($this->expected() - $this->historical) / $this->historical * 100, 1);
    }

    /**
     * Whether the pipeline is promising noticeably more than usual.
     */
    public function isOptimistic(float $tolerance = 25.0): bool
    {
        $gap = $this->versusHistory();

        return $gap !== null && $gap > $tolerance;
    }

    /**
     * @return array<string, float|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period->label,
            'committed' => $this->committed,
            'weighted' => $this->weighted,
            'expected' => $this->expected(),
            'best_case' => $this->bestCase,
            'historical' => $this->historical,
            'open_count' => $this->openCount,
            'versus_history' => $this->versusHistory(),
        ];
    }
}
