<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Models\Deal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record why a deal ended.
 *
 * Closing is two things happening together — the deal reaches a closing stage,
 * and somebody says why — and they are done here as one operation so a deal
 * cannot end up closed with a reason that contradicts its stage.
 *
 * The reason must belong to the outcome the stage carries. Recording a won deal
 * as "lost to a competitor" would corrupt the win/loss report in a way nobody
 * would spot until the quarter was over.
 */
class CloseDealAction
{
    public function __construct(private readonly MoveDealStageAction $moveStage) {}

    /**
     * @throws RuntimeException when the stage is not a closing one, or the
     *                          reason does not belong to its outcome
     */
    public function __invoke(
        Deal $deal,
        string $stageKey,
        DealCloseReason $reason,
        ?string $notes = null,
    ): Deal {
        DB::transaction(function () use ($deal, $stageKey, $reason, $notes) {
            // Moves first, and it is what validates the stage against the
            // pipeline. It also stamps closed_at.
            ($this->moveStage)($deal, $stageKey);

            $outcome = $deal->refresh()->outcome();

            if (! $outcome->isClosed()) {
                throw new RuntimeException(
                    '"'.$stageKey.'" does not close a deal, so there is no outcome to give a reason for.'
                );
            }

            if (! $reason->isFor($outcome)) {
                throw new RuntimeException(
                    '"'.$reason->label().'" is a '.$reason->outcome()->label()
                    .' reason, and this deal is '.$outcome->label().'.'
                );
            }

            $deal->forceFill([
                'close_reason' => $reason->value,
                'close_notes' => $notes === null || trim($notes) === '' ? null : trim($notes),
                // A deal already sitting in the closing stage was a no-op for
                // the move above, so the stamp is set here for that case.
                'closed_at' => $deal->closed_at ?? now(),
            ])->save();
        });

        return $deal->refresh();
    }

    /**
     * Put a closed deal back into play.
     *
     * The reason goes with it: a reopened deal that still says "lost on price"
     * reads as a closed one to everybody scanning the list.
     *
     * @throws RuntimeException
     */
    public function reopen(Deal $deal, string $stageKey): Deal
    {
        ($this->moveStage)($deal, $stageKey);

        $reopened = $deal->refresh();

        if (! $reopened->isOpen()) {
            throw new RuntimeException('"'.$stageKey.'" is another closing stage, so the deal is still closed.');
        }

        return $reopened;
    }
}
