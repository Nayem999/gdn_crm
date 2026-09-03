<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\DTOs\LeadScore;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Services\LeadScoring;
use Illuminate\Database\Eloquent\Builder;

class ScoreLeadsAction
{
    /**
     * How many ids go into one UPDATE ... WHERE id IN (...).
     */
    public const CHUNK = 500;

    public function __construct(private readonly LeadScoring $scoring) {}

    /**
     * Recompute and store the score for every lead the query selects.
     *
     * Written with the query builder, not the model, so a rescore of the whole
     * database does not put one audit entry per lead on the trail. A score is
     * derived from the rules; the rules themselves are what gets audited.
     *
     * @param  Builder<Lead>|null  $query  Defaults to every lead.
     * @return int How many leads were scored.
     */
    public function __invoke(?Builder $query = null): int
    {
        $query ??= Lead::query();

        $points = $this->scoring->pointsFor($query);
        $now = now();
        $scored = 0;

        // Leads matching no rule still need writing: their score may have been
        // higher before the rules changed.
        $byScore = [];

        foreach ($query->clone()->pluck('leads.id') as $id) {
            $id = (int) $id;
            $byScore[LeadScore::from($points[$id] ?? 0)->score][] = $id;
            $scored++;
        }

        foreach ($byScore as $score => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                Lead::query()->whereKey($chunk)->toBase()->update([
                    'score' => $score,
                    'scored_at' => $now,
                ]);
            }
        }

        return $scored;
    }
}
