<?php

namespace App\Jobs;

use App\Domain\Ingestion\Actions\ProcessIntegrationEventAction;
use App\Domain\Ingestion\Models\IntegrationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one delivery through the pipeline, off the request.
 *
 * Carries the **id**, not the model: a serialised model in a payload is a
 * snapshot of a row that has since moved on, and this one is written to by the
 * very action the job runs.
 *
 * One attempt. The action catches its own failures and writes them onto the
 * event, so a retry would re-run work that has already been accounted for —
 * and a delivery that failed because the payload was wrong will fail again in
 * exactly the same way. Replaying deliberately is 8.9's job, after somebody has
 * fixed the mapping.
 */
class ProcessIntegrationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $eventId) {}

    public function handle(ProcessIntegrationEventAction $process): void
    {
        $event = IntegrationEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $process($event);
    }
}
