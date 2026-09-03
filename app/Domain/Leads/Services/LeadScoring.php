<?php

namespace App\Domain\Leads\Services;

use App\Domain\Leads\DTOs\LeadScore;
use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns the active scoring rules into points.
 */
class LeadScoring
{
    public function __construct(private readonly LeadRuleMatcher $matcher) {}

    /**
     * @return Collection<int, LeadScoringRule>
     */
    public function rules(): Collection
    {
        return LeadScoringRule::query()
            ->ofKind(LeadRuleKind::Score)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * One lead's score with the rules that produced it, which is what the
     * detail page shows so a number is never unexplained.
     */
    public function scoreFor(Lead $lead): LeadScore
    {
        $points = 0;
        $matched = [];

        foreach ($this->rules() as $rule) {
            if (! $this->matcher->matches($rule, $lead)) {
                continue;
            }

            $points += $rule->points;
            $matched[] = ['label' => $rule->label, 'points' => $rule->points];
        }

        return LeadScore::from($points, $matched);
    }

    /**
     * Raw points for every lead the query selects, keyed by id.
     *
     * One query per rule over the whole set, rather than one set of queries per
     * lead — a rescore of the database is a handful of queries, not thousands.
     *
     * @param  Builder<Lead>  $query
     * @return array<int, int>
     */
    public function pointsFor(Builder $query): array
    {
        $points = [];

        foreach ($this->rules() as $rule) {
            foreach ($this->matcher->matchingIds($rule, $query) as $id) {
                $points[$id] = ($points[$id] ?? 0) + $rule->points;
            }
        }

        return $points;
    }
}
