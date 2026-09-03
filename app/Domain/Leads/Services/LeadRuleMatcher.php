<?php

namespace App\Domain\Leads\Services;

use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides which leads a rule matches, by handing its condition to the shared
 * filter engine.
 *
 * Evaluating in SQL rather than in PHP is the point: scoring, qualification and
 * the filter chips then agree by construction, including the awkward parts —
 * NULL-aware negation, collation-driven case handling, date boundaries.
 *
 * Rules are never applied to a user-scoped query. A score belongs to the lead,
 * not to whoever happens to be looking at it.
 */
class LeadRuleMatcher
{
    public function __construct(private readonly FilterApplier $applier) {}

    /**
     * The ids, among those the query already selects, that the rule matches.
     *
     * @param  Builder<Lead>  $query
     * @return array<int, int>
     */
    public function matchingIds(LeadScoringRule $rule, Builder $query): array
    {
        if (! $rule->isUsable()) {
            return [];
        }

        return $this->constrain($query->clone(), $rule)
            ->pluck('leads.id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
    }

    public function matches(LeadScoringRule $rule, Lead $lead): bool
    {
        if (! $rule->isUsable()) {
            return false;
        }

        return $this->constrain(Lead::query()->whereKey($lead->getKey()), $rule)->exists();
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function constrain(Builder $query, LeadScoringRule $rule): Builder
    {
        /** @var Builder<Lead> $constrained */
        $constrained = $this->applier->apply(
            $query,
            new FilterGroup(conditions: [$rule->condition()]),
            LeadFields::filters()
        );

        return $constrained;
    }
}
