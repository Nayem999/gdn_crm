<?php

namespace App\Domain\Meta\Analytics;

use App\Domain\Company\Models\Company;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Enums\MetaAdLevel;
use App\Domain\Meta\Models\MetaAd;
use App\Domain\Meta\Models\MetaAdSet;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Models\MetaInsight;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The chain: campaign → ad set → ad → lead → deal → revenue.
 *
 * Every figure comes from a **stored row**. Nothing here calls Meta, and that is
 * a decision rather than an optimisation: a screen that reads live would be
 * slow, would break when a token lapses, and would show different numbers to
 * two people looking at once. 12.7's sync owns what Meta thinks; this owns what
 * the CRM can prove.
 *
 * **Both halves are scoped to the viewer.** Spend is installation-wide — Meta
 * charged the company, not a salesperson — but leads and deals go through each
 * module's own `visibleTo()`, because an aggregate is the easiest kind of leak
 * to miss: "৳400,000 of revenue" is information about deals somebody may not
 * open. So two people can legitimately see the same spend against different
 * revenue, and the screen says whose figures they are.
 *
 * **Two different dates, deliberately.** Leads are counted by when they
 * *arrived* and deals by when they *closed*, because those are the questions
 * each answers. A deal closed this month usually came from a lead captured in
 * another one, so ROI over a short window is a genuine approximation — the
 * screen says so rather than implying a tidy attribution the data cannot
 * support.
 */
class CampaignPerformance
{
    /**
     * Campaign-level rows for a period.
     *
     * @return Collection<int, PerformanceRow>
     */
    public function campaigns(User $viewer, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $names = MetaCampaign::query()->pluck('name', 'meta_campaign_id');

        return $this->rows(
            $viewer,
            MetaAdLevel::Campaign,
            'meta_campaign_id',
            $names,
            $from,
            $to,
        );
    }

    /**
     * The ad sets inside one campaign.
     *
     * @return Collection<int, PerformanceRow>
     */
    public function adSets(User $viewer, string $campaignId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $names = MetaAdSet::query()
            ->where('meta_campaign_id', $campaignId)
            ->pluck('name', 'meta_ad_set_id');

        return $this->rows($viewer, MetaAdLevel::AdSet, 'meta_ad_set_id', $names, $from, $to);
    }

    /**
     * The advertisements inside one ad set.
     *
     * @return Collection<int, PerformanceRow>
     */
    public function ads(User $viewer, string $adSetId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $names = MetaAd::query()
            ->where('meta_ad_set_id', $adSetId)
            ->pluck('name', 'meta_ad_id');

        return $this->rows($viewer, MetaAdLevel::Ad, 'meta_ad_id', $names, $from, $to);
    }

    /**
     * One row summing everything visible, for the dashboard's cards.
     */
    public function total(User $viewer, CarbonInterface $from, CarbonInterface $to): PerformanceRow
    {
        $rows = $this->campaigns($viewer, $from, $to);

        return new PerformanceRow(
            id: 'all',
            name: 'All Meta advertising',
            spend: (float) $rows->sum('spend'),
            // Only when every row agrees. An agency running one account in BDT
            // and another in USD must never see the two added up under one
            // symbol, so the total goes unlabelled rather than wrong.
            currency: $rows->pluck('currency')->filter()->unique()->count() === 1
                ? $rows->pluck('currency')->filter()->first()
                : null,
            impressions: (int) $rows->sum('impressions'),
            clicks: (int) $rows->sum('clicks'),
            leads: (int) $rows->sum('leads'),
            qualified: (int) $rows->sum('qualified'),
            deals: (int) $rows->sum('deals'),
            won: (int) $rows->sum('won'),
            revenue: (float) $rows->sum('revenue'),
            revenueCurrency: $this->revenueCurrency(),
            reportedLeads: (int) $rows->sum('reportedLeads'),
        );
    }

    /**
     * What this company's own money is counted in.
     *
     * Read once per request: the deals carry no currency of their own — the
     * company has one — and the comparison with Meta's matters because the two
     * are frequently different.
     */
    private function revenueCurrency(): ?string
    {
        $currency = Company::current()->currency;

        return $currency === '' ? null : $currency;
    }

    /**
     * @param  Collection<string, string>  $names  Meta id => name, for the level asked for.
     * @return Collection<int, PerformanceRow>
     */
    private function rows(
        User $viewer,
        MetaAdLevel $level,
        string $attributionColumn,
        Collection $names,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        $spend = $this->spend($level, $from, $to);
        $leads = $this->leadCounts($viewer, $attributionColumn, $from, $to);
        $deals = $this->dealCounts($viewer, $attributionColumn, $from, $to);

        // Every id that appears anywhere: a campaign that spent and produced
        // nothing matters as much as one that produced leads after it stopped
        // spending, and a list built from one side would hide the other.
        $ids = $names->keys()
            ->merge($spend->keys())
            ->merge($leads->keys())
            ->merge($deals->keys())
            ->unique()
            ->values();

        return $ids
            ->map(function (string $id) use ($names, $spend, $leads, $deals): PerformanceRow {
                $money = $spend->get($id);
                $lead = $leads->get($id);
                $deal = $deals->get($id);

                return new PerformanceRow(
                    id: $id,
                    name: $names->get($id) ?? 'Not synced yet ('.$id.')',
                    spend: (float) ($money->spend ?? 0),
                    currency: $money->currency ?? null,
                    impressions: (int) ($money->impressions ?? 0),
                    clicks: (int) ($money->clicks ?? 0),
                    leads: (int) ($lead->total ?? 0),
                    qualified: (int) ($lead->qualified ?? 0),
                    deals: (int) ($deal->total ?? 0),
                    won: (int) ($deal->won ?? 0),
                    revenue: (float) ($deal->revenue ?? 0),
                    revenueCurrency: $this->revenueCurrency(),
                    reportedLeads: (int) ($money->leads ?? 0),
                );
            })
            ->sortByDesc(fn (PerformanceRow $row): float => $row->spend)
            ->values();
    }

    /**
     * What Meta charged, per entity, over the period.
     *
     * @return EloquentCollection<array-key, MetaInsight>
     */
    private function spend(MetaAdLevel $level, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return MetaInsight::query()
            ->selectRaw('entity_id, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(leads) as leads, MAX(currency) as currency')
            ->where('level', $level->value)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('entity_id')
            ->get()
            ->keyBy('entity_id');
    }

    /**
     * Leads attributed to each entity, counted by when they arrived.
     *
     * @return EloquentCollection<array-key, Model>
     */
    private function leadCounts(User $viewer, string $column, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->attributed(Lead::query()->visibleTo($viewer), Lead::class, $column, $from, $to)
            ->selectRaw(sprintf(
                'marketing_attributions.%s as entity_id, COUNT(*) as total, SUM(CASE WHEN leads.status IN (?, ?) THEN 1 ELSE 0 END) as qualified',
                $column,
            ), [LeadStatus::Qualified->value, LeadStatus::Converted->value])
            ->groupBy('marketing_attributions.'.$column)
            ->get()
            ->keyBy('entity_id');
    }

    /**
     * Deals attributed to each entity, counted by when they closed.
     *
     * @return EloquentCollection<array-key, Deal>
     */
    private function dealCounts(User $viewer, string $column, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Deal::query()
            ->visibleTo($viewer)
            ->join('marketing_attributions', function ($join) use ($column): void {
                $join->on('marketing_attributions.attributable_id', '=', 'deals.id')
                    ->where('marketing_attributions.attributable_type', '=', Deal::class)
                    ->whereNotNull('marketing_attributions.'.$column);
            })
            // A deal's own moment, not its lead's: "what did this campaign earn
            // in September" is a question about money that arrived in
            // September.
            ->whereBetween('deals.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw(sprintf(
                'marketing_attributions.%s as entity_id, COUNT(*) as total, '
                .'SUM(CASE WHEN deals.stage = ? THEN 1 ELSE 0 END) as won, '
                .'SUM(CASE WHEN deals.stage = ? THEN deals.value ELSE 0 END) as revenue',
                $column,
            ), [DealStage::Won->value, DealStage::Won->value])
            ->groupBy('marketing_attributions.'.$column)
            ->get()
            ->keyBy('entity_id');
    }

    /**
     * A module's visible query, joined to its attribution rows for the period.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  class-string  $type
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    private function attributed(Builder $query, string $type, string $column, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->join('marketing_attributions', function ($join) use ($table, $type, $column): void {
                $join->on('marketing_attributions.attributable_id', '=', $table.'.id')
                    ->where('marketing_attributions.attributable_type', '=', $type)
                    ->whereNotNull('marketing_attributions.'.$column);
            })
            ->whereBetween('marketing_attributions.captured_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }
}
