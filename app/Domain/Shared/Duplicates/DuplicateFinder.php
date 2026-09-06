<?php

namespace App\Domain\Shared\Duplicates;

use App\Domain\Shared\Models\DuplicateKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds the records that look like duplicates of a given one.
 *
 * Two queries, whatever the table size: one over duplicate_keys to gather
 * candidate ids by fingerprint, one to load the candidates the viewer may
 * actually see. The visibility scope is applied to that second query rather
 * than filtered afterwards, so a hint can never name a record outside the
 * viewer's access level.
 *
 * Scoring is per strategy, not per rule. A lead's phone and mobile are two
 * rules on the same strategy; matching both is still one telephone number's
 * worth of evidence, and counting it twice would push a coincidence into the
 * "almost certainly" band.
 */
class DuplicateFinder
{
    /**
     * @param  int|null  $ignoring  Id to leave out, for a draft that has no key yet.
     * @return array<int, DuplicateMatch> Strongest first.
     */
    public function for(DuplicateSource $source, Model $record, User $user, ?int $ignoring = null): array
    {
        $scores = $this->candidateScores($source, $record, $ignoring);

        if ($scores === []) {
            return [];
        }

        $candidates = $source->visibleQuery($user)
            ->whereKey(array_keys($scores))
            // A record already merged away is a resolved duplicate, not one to
            // resolve again.
            ->whereNull('merged_into_id')
            ->get()
            ->keyBy(fn (Model $candidate) => (int) $candidate->getKey());

        $matches = [];

        foreach ($scores as $id => $scored) {
            $candidate = $candidates->get($id);

            if ($candidate === null || DuplicateConfidence::forScore($scored['score']) === null) {
                continue;
            }

            $matches[] = new DuplicateMatch($candidate, $scored['reasons'], $scored['score']);
        }

        usort($matches, fn (DuplicateMatch $a, DuplicateMatch $b) => $b->score <=> $a->score);

        return $matches;
    }

    /**
     * The fingerprints a record should have stored.
     *
     * @return array<int, array{kind: string, value: string}>
     */
    public function fingerprints(DuplicateSource $source, Model $record): array
    {
        $rows = [];

        foreach ($this->rulesByFingerprint($source, $record) as $key => $rule) {
            [$kind, $value] = explode('|', $key, 2);
            $rows[] = ['kind' => $kind, 'value' => $value];
        }

        return $rows;
    }

    /**
     * Candidate ids with their score and the reasons behind it.
     *
     * @return array<int, array{score: int, reasons: array<int, string>}>
     */
    private function candidateScores(DuplicateSource $source, Model $record, ?int $ignoring = null): array
    {
        $rules = $this->rulesByFingerprint($source, $record);

        if ($rules === []) {
            return [];
        }

        $query = DuplicateKey::query()
            ->where('keyable_type', $source->modelClass())
            ->where(function ($outer) use ($rules) {
                foreach (array_keys($rules) as $key) {
                    [$kind, $value] = explode('|', $key, 2);

                    $outer->orWhere(function ($inner) use ($kind, $value) {
                        $inner->where('kind', $kind)->where('value', $value);
                    });
                }
            });

        // An unsaved draft has no key of its own, so the id to leave out is
        // passed in when a form is checking what is being typed.
        $exclude = $ignoring ?? ($record->exists ? (int) $record->getKey() : null);

        if ($exclude !== null) {
            $query->where('keyable_id', '!=', $exclude);
        }

        /** @var array<int, array{strategies: array<string, string>}> $hits */
        $hits = [];

        foreach ($query->get(['keyable_id', 'kind', 'value']) as $row) {
            $rule = $rules[$row->kind.'|'.$row->value] ?? null;

            if ($rule === null) {
                continue;
            }

            $id = (int) $row->keyable_id;
            // Keyed by strategy so two rules sharing one cannot both score.
            $hits[$id]['strategies'][$rule->strategy->value] ??= $rule->label();
        }

        $scores = [];

        foreach ($hits as $id => $hit) {
            $score = 0;

            foreach (array_keys($hit['strategies']) as $strategy) {
                $score += MatchStrategy::from($strategy)->weight();
            }

            $scores[$id] = [
                'score' => $score,
                'reasons' => array_values($hit['strategies']),
            ];
        }

        return $scores;
    }

    /**
     * This record's fingerprints, each mapped to the rule that produced it.
     *
     * Keyed by "kind|value" so a value two rules both produce is stored and
     * looked up once — the unique index would refuse the second row anyway.
     *
     * @return array<string, MatchRule>
     */
    private function rulesByFingerprint(DuplicateSource $source, Model $record): array
    {
        $rules = [];

        foreach ($source->rules() as $rule) {
            $value = $rule->fingerprint($record);

            if ($value === null) {
                continue;
            }

            $rules[$rule->strategy->value.'|'.$value] ??= $rule;
        }

        return $rules;
    }
}
