<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Jobs\ProcessIntegrationEvent;

/**
 * Runs a delivery through the pipeline again.
 *
 * What makes replay worth having is that the **body was kept**: after somebody
 * fixes a mapping, the deliveries that failed because of it can be put through
 * the corrected rules rather than being asked for again from a system that may
 * no longer have them.
 *
 * The event is reset rather than copied. A second row for one delivery would
 * make the log double-count what arrived, and "how many did they send us" is a
 * question the log has to be able to answer.
 *
 * Replaying something that already produced a record is safe: the pipeline
 * matches on the sender's own id through the log, so the same delivery lands on
 * the same record and updates it. That is also why a replay after a mapping fix
 * corrects the record rather than making a second one.
 */
class ReplayIntegrationEventAction
{
    public function __invoke(IntegrationEvent $event): IntegrationEvent
    {
        $event->forceFill([
            'status' => IntegrationEventStatus::Received->value,
            'outcome' => null,
            'error' => null,
            'processed_at' => null,
            // The mapped output is cleared too: leaving the old one would show
            // yesterday's mapping next to today's outcome.
            'mapped_output' => null,
            // `attempts` is deliberately **not** reset. It counts how many
            // times this delivery has been through the pipeline, and a replay
            // is one of those times.
        ])->save();

        ProcessIntegrationEvent::dispatch($event->id);

        return $event->refresh();
    }
}
