<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of a deal's stage.
 *
 * Nothing else may touch the column: `DealData` has no stage field, the form has
 * no stage control, and 3.3's board drag calls this. That is what keeps the
 * closing stamp and the stage from ever disagreeing — the same arrangement
 * ChangeLeadStatusAction has for lead status.
 *
 * Reaching a stage whose outcome closes the deal stamps `closed_at`; moving
 * back to an open stage clears the stamp and the reason, because a reopened
 * deal that still says "lost on price" is worse than one that says nothing.
 */
class MoveDealStageAction
{
    /**
     * @throws RuntimeException when the stage does not belong to the deal's pipeline
     */
    public function __invoke(Deal $deal, string $stageKey): bool
    {
        $stage = $this->resolve($deal, $stageKey);

        if ($deal->stage === $stageKey) {
            // A no-op, so the closing stamp is left exactly where it was
            // rather than being pushed forward by a re-click.
            return false;
        }

        $wasOpen = $deal->isOpen();
        $becomesClosed = $stage === null
            ? in_array($stageKey, Deal::closingStageKeys(), true)
            : $stage->isClosed();

        DB::transaction(function () use ($deal, $stageKey, $wasOpen, $becomesClosed) {
            $attributes = ['stage' => $stageKey];

            if ($becomesClosed && $wasOpen) {
                $attributes['closed_at'] = now();
            }

            if (! $becomesClosed) {
                $attributes['closed_at'] = null;
                $attributes['close_reason'] = null;
                $attributes['close_notes'] = null;
            }

            $deal->forceFill($attributes)->save();
        });

        return true;
    }

    /**
     * The stage a key names on this deal's pipeline.
     *
     * A key that names no stage there is refused rather than written: the board
     * would show the deal in no column at all, which is how a record goes
     * missing. Null is returned only when there is no pipeline to check
     * against, which is what a 2.6 deal looks like — those fall back to the
     * DealStage enum.
     *
     * @throws RuntimeException
     */
    private function resolve(Deal $deal, string $stageKey): ?PipelineStage
    {
        $pipeline = $deal->pipeline ?? Pipeline::default();

        if ($pipeline === null) {
            if (DealStage::tryFrom($stageKey) === null) {
                throw new RuntimeException('"'.$stageKey.'" is not a stage this deal can be in.');
            }

            return null;
        }

        $stage = $pipeline->stageByKey($stageKey);

        if ($stage === null) {
            throw new RuntimeException(
                '"'.$stageKey.'" is not a stage on the '.$pipeline->name.' pipeline.'
            );
        }

        return $stage;
    }

    /**
     * Move a deal without checking the pipeline, for a caller that already
     * knows the stage is right.
     *
     * Exists for lead conversion, which creates a deal at the first stage of
     * whichever pipeline it lands on. Do not widen it into a general escape
     * hatch — the point of this class is that stage moves are checked.
     */
    public function force(Deal $deal, string $stageKey, StageOutcome $outcome = StageOutcome::Open): void
    {
        $deal->forceFill([
            'stage' => $stageKey,
            'closed_at' => $outcome->isClosed() ? now() : null,
        ])->save();
    }
}
