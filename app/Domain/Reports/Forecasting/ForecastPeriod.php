<?php

namespace App\Domain\Reports\Forecasting;

use Illuminate\Support\Carbon;

/**
 * One month of a forecast.
 *
 * Months, not arbitrary windows: a sales forecast is quoted by month or by
 * quarter everywhere else in a company, and a forecast that used a rolling
 * thirty days would never agree with the figure anybody else is holding.
 */
readonly class ForecastPeriod
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public string $label,
    ) {}

    public static function month(Carbon $anyDayInIt): self
    {
        $start = $anyDayInIt->copy()->startOfMonth();

        return new self($start, $start->copy()->endOfMonth(), $start->format('Y-m'));
    }

    /**
     * The months from this one forward, inclusive.
     *
     * @return array<int, self>
     */
    public static function ahead(int $months, ?Carbon $from = null): array
    {
        $cursor = ($from ?? now())->copy()->startOfMonth();
        $periods = [];

        for ($index = 0; $index < max(1, $months); $index++) {
            $periods[] = self::month($cursor);
            $cursor->addMonthNoOverflow();
        }

        return $periods;
    }

    /**
     * The months before this one, oldest first, excluding the current month.
     *
     * The current month is left out on purpose: it is half over, and averaging
     * a part-month in with whole ones drags every historical figure down.
     *
     * @return array<int, self>
     */
    public static function behind(int $months, ?Carbon $before = null): array
    {
        $cursor = ($before ?? now())->copy()->startOfMonth()->subMonthsNoOverflow(max(1, $months));
        $periods = [];

        for ($index = 0; $index < max(1, $months); $index++) {
            $periods[] = self::month($cursor);
            $cursor->addMonthNoOverflow();
        }

        return $periods;
    }

    public function contains(?Carbon $moment): bool
    {
        return $moment !== null && $moment->betweenIncluded($this->from, $this->to);
    }
}
