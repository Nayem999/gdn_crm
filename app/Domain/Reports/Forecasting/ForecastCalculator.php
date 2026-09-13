<?php

namespace App\Domain\Reports\Forecasting;

use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What the company is likely to close, and what it has closed before.
 *
 * Two forecasts, deliberately side by side rather than blended into one
 * number:
 *
 *   - **Pipeline-weighted** — what is open, each deal counted at its stage's
 *     probability. It knows about the deals that actually exist, and it is
 *     optimistic in exactly the way salespeople are.
 *   - **Historical** — the average of what actually closed over the last few
 *     months. It knows nothing about the pipeline, and it is the number that
 *     turns out to be right when the pipeline is full of wishes.
 *
 * A single blended figure would hide which of the two a reader should believe,
 * and the gap between them is the most useful thing on the screen: a weighted
 * forecast far above the historical average is a pipeline that is not going to
 * land.
 *
 * Every figure is drawn through `visibleTo()`, so a forecast is of the deals
 * the reader could open one by one.
 */
class ForecastCalculator
{
    /**
     * How many past months the historical average is drawn from.
     *
     * Three is too few to survive one unusual month; twelve reaches back past
     * a changed product or a changed team. Six is the compromise, and it is a
     * constant so a test can name it rather than repeating the number.
     */
    public const HISTORY_MONTHS = 6;

    /**
     * How far ahead a forecast is offered.
     */
    public const DEFAULT_MONTHS = 3;

    /**
     * @param  array<int, ForecastPeriod>|null  $periods
     * @return array<int, Forecast>
     */
    public function forecast(User $viewer, ?array $periods = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $periods ??= ForecastPeriod::ahead(self::DEFAULT_MONTHS, $now);

        $probabilities = $this->probabilities();
        $historical = $this->historicalAverage($viewer, $now);

        $forecasts = [];

        foreach ($periods as $period) {
            $open = $this->openDealsIn($viewer, $period);
            $won = $this->wonValueIn($viewer, $period);

            $weighted = 0.0;
            $pipeline = 0.0;

            foreach ($open as $deal) {
                $value = (float) $deal->value;
                $pipeline += $value;
                $weighted += round($value * $this->probabilityFor($deal, $probabilities) / 100, 2);
            }

            $forecasts[] = new Forecast(
                period: $period,
                committed: $won,
                weighted: round($weighted, 2),
                // Best case is everything open landing, on top of what is
                // already won. Named "best case" rather than "forecast"
                // because it is the number nobody should plan on.
                bestCase: round($won + $pipeline, 2),
                historical: $historical,
                openCount: $open->count(),
            );
        }

        return $forecasts;
    }

    /**
     * What has actually closed, month by month, for the history behind the
     * average.
     *
     * @return array<string, float>
     */
    public function history(User $viewer, ?Carbon $now = null): array
    {
        $months = [];

        foreach (ForecastPeriod::behind(self::HISTORY_MONTHS, $now ?? now()) as $period) {
            $months[$period->label] = $this->wonValueIn($viewer, $period);
        }

        return $months;
    }

    /**
     * The mean of the last few whole months' won value.
     *
     * The mean of every month including the empty ones, not of the months that
     * had something: a quiet month is evidence, and dropping it would forecast
     * the good months only.
     */
    public function historicalAverage(User $viewer, ?Carbon $now = null): float
    {
        $months = $this->history($viewer, $now);

        return $months === [] ? 0.0 : round(array_sum($months) / count($months), 2);
    }

    /**
     * Open deals expected to close inside a period.
     *
     * By `expected_close_date`, which is the salesperson's own answer to "when
     * will this land" — a forecast built on anything else would be the
     * application's guess rather than theirs.
     *
     * @return Collection<int, Deal>
     */
    private function openDealsIn(User $viewer, ForecastPeriod $period): Collection
    {
        return Deal::query()
            ->visibleTo($viewer)
            ->whereBetween('expected_close_date', [$period->from, $period->to])
            // Open only: a deal already won in this month is committed, and
            // counting it in both would forecast it twice.
            ->whereNotIn('stage', [StageOutcome::Won->value, StageOutcome::Lost->value])
            ->get(['id', 'value', 'stage', 'pipeline_id']);
    }

    /**
     * What was actually won in a period, by when it closed.
     */
    private function wonValueIn(User $viewer, ForecastPeriod $period): float
    {
        return round((float) Deal::query()
            ->visibleTo($viewer)
            ->where('stage', StageOutcome::Won->value)
            ->whereBetween('closed_at', [$period->from, $period->to])
            ->sum('value'), 2);
    }

    /**
     * Every configured stage's probability, keyed by pipeline and stage key.
     *
     * Read once for the whole forecast rather than per deal: a forecast over
     * three months touches every open deal, and a lookup each would be a query
     * per row.
     *
     * @return array<string, int>
     */
    private function probabilities(): array
    {
        $map = [];

        foreach (Pipeline::query()->with('stages')->get() as $pipeline) {
            foreach ($pipeline->stages as $stage) {
                $map[$pipeline->id.':'.$stage->key] = (int) $stage->probability;
            }
        }

        return $map;
    }

    /**
     * A deal's probability: its configured stage's, or the enum's default when
     * the deal is on no pipeline — the same fallback Deal::weightedValue()
     * uses, so the forecast and the deal page cannot disagree.
     *
     * @param  array<string, int>  $probabilities
     */
    private function probabilityFor(Deal $deal, array $probabilities): int
    {
        $key = $deal->pipeline_id.':'.$deal->getAttributeValue('stage');

        if (array_key_exists($key, $probabilities)) {
            return $probabilities[$key];
        }

        return $deal->stage()->probability();
    }
}
