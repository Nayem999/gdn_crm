<?php

namespace App\Domain\Dashboard;

use App\Domain\Company\Models\Company;
use App\Domain\Dashboard\Enums\DashboardPeriod;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Meta\Analytics\CampaignPerformance;
use App\Domain\Settings\NumberFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

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
            ...$this->metaKpis($scope, $period),
        ]));
    }

    /**
     * What the advertising cost, and what it cost per lead.
     *
     * Only for somebody who may see the campaign figures, and only when there
     * is spend in the window: an installation that does not advertise should
     * not carry two permanently empty cards, and a zero would claim the
     * advertising produced nothing rather than that there was none.
     *
     * The spend is **not** scoped by the viewer's access level, because Meta
     * charged the company rather than a salesperson — but the leads counted
     * against it are, which is why the cost per lead on this card is the
     * viewer's own and the screen behind it says so.
     *
     * @return array<int, Kpi|null>
     */
    private function metaKpis(DashboardScope $scope, DashboardPeriod $period): array
    {
        if (! $scope->viewer()->can('meta.campaigns.view')) {
            return [];
        }

        $row = app(CampaignPerformance::class)->total(
            $scope->viewer(),
            $period->startsAt(),
            $period->endsAt(),
        );

        if ($row->spend <= 0.0 && $row->leads === 0) {
            return [];
        }

        $perLead = $row->costPerLead();

        return [
            new Kpi(
                key: 'meta_spend',
                label: 'Meta spend',
                value: NumberFormat::format($row->spend, 0),
                raw: $row->spend,
                icon: 'megaphone',
                color: 'sky',
                unit: $row->currency,
                caption: $row->leads.' '.Str::plural('lead', $row->leads).' attributed',
                href: route('settings.meta.performance'),
            ),
            new Kpi(
                key: 'meta_cost_per_lead',
                label: 'Cost per lead',
                // A dash rather than a zero: a campaign that has spent and
                // produced nothing has no cost per lead, and zero would be the
                // opposite claim.
                value: $perLead === null ? '—' : NumberFormat::format($perLead, 0),
                raw: $perLead ?? 0,
                icon: 'target',
                color: 'violet',
                unit: $perLead === null ? null : $row->currency,
                caption: $row->qualified.' qualified',
                href: route('settings.meta.performance'),
            ),
        ];
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
