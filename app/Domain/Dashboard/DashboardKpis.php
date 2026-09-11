<?php

namespace App\Domain\Dashboard;

use App\Domain\Company\Models\Company;
use App\Domain\Dashboard\Enums\DashboardPeriod;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Settings\NumberFormat;
use Illuminate\Database\Eloquent\Builder;

/**
 * The headline figures, built from the viewer's own scope.
 *
 * A card appears only when its module does. Somebody with no deals permission
 * gets no pipeline figures at all rather than a row of zeroes — a zero says
 * "there are none", which is a different and wrong answer.
 */
class DashboardKpis
{
    /**
     * @return array<int, Kpi>
     */
    public function for(DashboardScope $scope, DashboardPeriod $period): array
    {
        return array_values(array_filter([
            ...$this->dealKpis($scope, $period),
            $this->newLeads($scope, $period),
            $this->overdueActivities($scope),
        ]));
    }

    /**
     * Open count, open value and what was won in the window.
     *
     * Three reads rather than one grouped query: they answer different
     * questions (open by stage, won by close date) and a single aggregate that
     * tried to do both would need a CASE over two different date columns.
     *
     * @return array<int, Kpi|null>
     */
    private function dealKpis(DashboardScope $scope, DashboardPeriod $period): array
    {
        $deals = $scope->query('deals');

        if ($deals === null) {
            return [];
        }

        /** @var Builder<Deal> $deals */
        $openCount = (clone $deals)->open()->count();
        $openValue = (float) (clone $deals)->open()->sum('deals.value');

        $won = (clone $deals)
            ->withOutcome(StageOutcome::Won)
            // closed_at, not created_at: a deal won this month was very
            // probably opened in another one, and counting by creation would
            // describe the pipeline's intake rather than its result.
            ->whereBetween('deals.closed_at', [$period->startsAt(), $period->endsAt()]);

        $wonCount = (clone $won)->count();
        $wonValue = (float) $won->sum('deals.value');

        return [
            new Kpi(
                key: 'open_deals',
                label: 'Open deals',
                value: NumberFormat::format($openCount, 0),
                raw: $openCount,
                icon: 'handshake',
                color: 'emerald',
                caption: 'Not yet won or lost',
                href: route('deals.index'),
            ),
            new Kpi(
                key: 'open_pipeline',
                label: 'Open pipeline',
                value: NumberFormat::format($openValue, 0),
                raw: $openValue,
                icon: 'trending-up',
                color: 'indigo',
                unit: $this->currency(),
                caption: 'Value of everything still open',
                href: route('deals.index'),
            ),
            new Kpi(
                key: 'won',
                label: 'Won '.$period->caption(),
                value: NumberFormat::format($wonValue, 0),
                raw: $wonValue,
                icon: 'trophy',
                color: 'amber',
                unit: $this->currency(),
                caption: $wonCount === 1 ? '1 deal closed' : NumberFormat::format($wonCount, 0).' deals closed',
                href: route('deals.index'),
            ),
        ];
    }

    private function newLeads(DashboardScope $scope, DashboardPeriod $period): ?Kpi
    {
        $leads = $scope->query('leads');

        if ($leads === null) {
            return null;
        }

        $count = $leads->whereBetween('leads.created_at', [$period->startsAt(), $period->endsAt()])->count();

        return new Kpi(
            key: 'new_leads',
            label: 'New leads',
            value: NumberFormat::format($count, 0),
            raw: $count,
            icon: 'target',
            color: 'violet',
            caption: 'Captured '.$period->caption(),
            href: route('leads.index'),
        );
    }

    /**
     * Late work is the one figure that is not about a window: something overdue
     * since March is still overdue, and hiding it inside "this month" is how it
     * stays that way.
     */
    private function overdueActivities(DashboardScope $scope): ?Kpi
    {
        $activities = $scope->activities();

        if ($activities === null) {
            return null;
        }

        $count = $activities->overdue()->count();

        return new Kpi(
            key: 'overdue_activities',
            label: 'Overdue',
            value: NumberFormat::format($count, 0),
            raw: $count,
            icon: 'alarm-clock',
            // Rose only when there is something to be alarmed about: a red zero
            // is a false alarm every morning.
            color: $count > 0 ? 'rose' : 'slate',
            caption: 'Tasks, calls and meetings past due',
            href: route('activities.index', ['chip' => 'overdue']),
        );
    }

    /**
     * The company's currency code. Amounts elsewhere print bare, so this labels
     * the card rather than being folded into the number.
     */
    private function currency(): ?string
    {
        $currency = Company::current()->currency;

        return $currency === '' ? null : $currency;
    }
}
