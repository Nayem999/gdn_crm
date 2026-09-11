<?php

namespace App\Domain\Dashboard;

use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deals by stage, for one pipeline.
 *
 * Per-pipeline for the same reason the board is (see .ai/rules/deals.md): a
 * funnel mixing two pipelines' stages puts a deal in a band that does not apply
 * to it, and the shape then describes nothing.
 *
 * Every configured stage gets a band, including the empty ones. A funnel that
 * omitted them would silently redraw itself as deals moved, and "nothing in
 * Negotiation" is the most useful thing a funnel can tell somebody.
 */
class PipelineFunnel
{
    /**
     * @return array<int, FunnelStage>
     */
    public function for(DashboardScope $scope, Pipeline $pipeline): array
    {
        $deals = $scope->query('deals');

        if ($deals === null) {
            return [];
        }

        /** @var Builder<Deal> $deals */
        $totals = $this->totals($deals, $pipeline);
        $stages = $pipeline->stages()->ordered()->get();

        $widest = 0;

        foreach ($stages as $stage) {
            $widest = max($widest, (int) ($totals[$stage->key]['count'] ?? 0));
        }

        $bands = [];

        foreach ($stages as $stage) {
            $count = (int) ($totals[$stage->key]['count'] ?? 0);

            $bands[] = new FunnelStage(
                key: $stage->key,
                name: $stage->name,
                count: $count,
                value: (float) ($totals[$stage->key]['value'] ?? 0.0),
                probability: (int) $stage->probability,
                outcome: $stage->outcome(),
                share: $widest === 0 ? 0.0 : round($count / $widest * 100, 2),
            );
        }

        return $bands;
    }

    /**
     * One grouped query over the whole pipeline, not a count per stage.
     *
     * `pipeline_id` is nullable and null means the default pipeline — see
     * .ai/rules/pipelines.md — so the default one has to match both. Getting
     * that wrong shows an empty funnel on a working installation, because
     * nothing backfills the column.
     *
     * @param  Builder<Deal>  $deals
     * @return array<string, array{count: int, value: float}>
     */
    private function totals(Builder $deals, Pipeline $pipeline): array
    {
        $query = (clone $deals)
            ->when(
                $pipeline->is_default,
                fn (Builder $inner) => $inner->where(
                    fn (Builder $either) => $either
                        ->where('deals.pipeline_id', $pipeline->getKey())
                        ->orWhereNull('deals.pipeline_id')
                ),
                fn (Builder $inner) => $inner->where('deals.pipeline_id', $pipeline->getKey()),
            );

        // `applyScopes()` before `getQuery()`, never `getQuery()` alone.
        // Eloquent applies its global scopes at execution time, so dropping
        // straight to the base builder silently discards them — and Deal
        // soft-deletes, which would put removed deals back in their bands.
        //
        // Aggregating on the base builder rather than on Eloquent keeps it to
        // one row per stage instead of hydrating a Deal per band for columns
        // that are not on the model.
        //
        // reorder() because the module's visible query sorts by name, and an
        // ORDER BY on a column outside the GROUP BY is refused under
        // ONLY_FULL_GROUP_BY.
        $rows = $query
            ->reorder()
            ->applyScopes()
            ->getQuery()
            ->select('deals.stage')
            ->selectRaw('COUNT(*) as aggregate_count')
            ->selectRaw('COALESCE(SUM(deals.value), 0) as aggregate_value')
            ->groupBy('deals.stage')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->stage] = [
                'count' => (int) $row->aggregate_count,
                'value' => (float) $row->aggregate_value,
            ];
        }

        return $totals;
    }

    /**
     * The pipelines a funnel can be drawn for, newest configuration order.
     *
     * @return array<int, Pipeline>
     */
    public function pipelines(): array
    {
        return Pipeline::query()->ordered()->get()->all();
    }
}
