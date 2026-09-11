<?php

namespace App\Livewire\Dashboard;

use App\Domain\Dashboard\DashboardKpis;
use App\Domain\Dashboard\Enums\DashboardPeriod;
use App\Domain\Dashboard\Kpi;
use App\Livewire\Dashboard\Concerns\ScopesToViewer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The headline figures.
 *
 * Lazy, like every widget here: four independent queries that would otherwise
 * hold up the whole page, and each one arrives behind its own skeleton.
 */
class DashboardKpiCards extends Component
{
    use ScopesToViewer;

    #[Url(as: 'period', except: 'month')]
    public string $period = 'month';

    /**
     * @return array<int, Kpi>
     */
    #[Computed]
    public function cards(): array
    {
        return app(DashboardKpis::class)->for($this->scope(), $this->currentPeriod());
    }

    public function currentPeriod(): DashboardPeriod
    {
        return DashboardPeriod::tryFrom($this->period) ?? DashboardPeriod::Month;
    }

    public function setPeriod(string $period): void
    {
        $resolved = DashboardPeriod::tryFrom($period);

        if ($resolved === null) {
            return;
        }

        $this->period = $resolved->value;
        unset($this->cards);
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return DashboardPeriod::options();
    }

    /**
     * Matches the card grid it stands in for, so the page does not jump when
     * the figures arrive.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="grid gap-4 grid-cols-1 sm:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))]" aria-hidden="true">
            <div class="h-28 animate-pulse rounded-xl border border-border bg-card"></div>
            <div class="h-28 animate-pulse rounded-xl border border-border bg-card"></div>
            <div class="h-28 animate-pulse rounded-xl border border-border bg-card"></div>
            <div class="h-28 animate-pulse rounded-xl border border-border bg-card"></div>
            <span class="sr-only">Loading figures…</span>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.dashboard-kpi-cards');
    }
}
