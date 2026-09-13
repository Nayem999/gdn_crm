<?php

namespace App\Livewire\Reports;

use App\Domain\Deals\Models\Deal;
use App\Domain\Reports\Forecasting\Forecast;
use App\Domain\Reports\Forecasting\ForecastCalculator;
use App\Domain\Reports\Forecasting\ForecastPeriod;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What is likely to close, next to what usually does.
 *
 * Both forecasts are shown side by side rather than blended: the gap between
 * the weighted pipeline and the historical average is the most useful thing on
 * the page, and a single number would hide it.
 */
#[Title('Sales forecast')]
class SalesForecast extends Component
{
    use AuthorizesRequests;

    /**
     * The longest forecast offered. Beyond a couple of quarters an expected
     * close date is a guess about a guess.
     */
    public const MAX_MONTHS = 12;

    #[Url(as: 'months', except: '3')]
    public string $months = '3';

    public function mount(): void
    {
        // Gated on deals rather than on reports: a forecast is the deals
        // module's figures, and somebody who cannot see a deal has no business
        // seeing the total of them.
        $this->authorize('viewAny', Deal::class);
    }

    public function monthCount(): int
    {
        return max(1, min(self::MAX_MONTHS, (int) $this->months));
    }

    /**
     * @return array<int, Forecast>
     */
    #[Computed]
    public function forecasts(): array
    {
        return app(ForecastCalculator::class)->forecast(
            $this->currentUser(),
            ForecastPeriod::ahead($this->monthCount()),
        );
    }

    /**
     * @return array<string, float>
     */
    #[Computed]
    public function history(): array
    {
        return app(ForecastCalculator::class)->history($this->currentUser());
    }

    public function historicalAverage(): float
    {
        return app(ForecastCalculator::class)->historicalAverage($this->currentUser());
    }

    /**
     * @return array{committed: float, weighted: float, expected: float, best_case: float}
     */
    public function totals(): array
    {
        $committed = 0.0;
        $weighted = 0.0;
        $bestCase = 0.0;

        foreach ($this->forecasts() as $forecast) {
            $committed += $forecast->committed;
            $weighted += $forecast->weighted;
            $bestCase += $forecast->bestCase;
        }

        return [
            'committed' => round($committed, 2),
            'weighted' => round($weighted, 2),
            'expected' => round($committed + $weighted, 2),
            'best_case' => round($bestCase, 2),
        ];
    }

    /**
     * Int-keyed however it is written: PHP casts a numeric string array key
     * straight back to an int.
     *
     * @return array<int, string>
     */
    public function monthOptions(): array
    {
        return ['3' => 'Next 3 months', '6' => 'Next 6 months', '12' => 'Next 12 months'];
    }

    public function updatedMonths(): void
    {
        unset($this->forecasts);
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    public function render(): View
    {
        return view('livewire.reports.sales-forecast');
    }
}
