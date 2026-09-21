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

    /**
     * Hand a delivery off to be processed, however this installation does it.
     *
     * The default is **after the response**, not the queue, and that is a
     * decision about who this software is for. A CRM on shared hosting has no
     * daemon and frequently no cron, so a queued job waits for a worker that
     * never runs: the delivery log fills up, the inbox stays empty, and
     * nothing anywhere says why. Processing once the sender has its 200 costs
     * that request a tenth of a second and makes the product work as shipped.
     *
     * Not `Artisan::call('queue:work')` in the request, which is the obvious
     * way to get the same effect: that drains *every* queued job — exports,
     * email, ad syncs — with Meta waiting on the response, and Meta retries
     * anything slow. This runs the one delivery that just arrived, after the
     * response has gone.
     *
     * An installation with a real worker sets INGESTION_PROCESS=queue and gets
     * retries, backoff and the work off the web tier, which is better when
     * there is something to do the work.
     */
    public static function start(int $eventId): void
    {
        if (config('ingestion.process') === 'queue') {
            self::dispatch($eventId);

            return;
        }

        self::dispatchAfterResponse($eventId);
    }

    public function handle(ProcessIntegrationEventAction $process): void
    {
        $event = IntegrationEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $process($event);
    }
}
