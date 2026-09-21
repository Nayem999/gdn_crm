<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ingestion\Actions\CaptureIngestEventAction;
use App\Domain\Ingestion\Enums\IngestRefusal;
use App\Domain\Ingestion\IngestGuard;
use App\Domain\Ingestion\Models\DataSource;
use App\Jobs\ProcessIntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/ingest/{source}` — the door an outside system posts records
 * through.
 *
 * Deliberately short. It resolves a source, asks the guard whether to accept,
 * writes the body down and answers `202 Accepted`. Nothing is parsed, nothing
 * is mapped, nothing is persisted into a business table on this request: the
 * sender is waiting, and a sender that waits times out and retries, and a retry
 * storm is how an integration takes an application down.
 *
 * The payload is **data**. It is written as bytes and never evaluated,
 * unserialised or executed, and nothing in it names a column, a class or a
 * module — the source's stored `target_module` decides that, in 8.4.
 */
class IngestController
{
    public function __construct(
        private readonly IngestGuard $guard,
        private readonly CaptureIngestEventAction $capture,
    ) {}

    public function __invoke(Request $request, string $source): JsonResponse
    {
        // Unknown, deleted and switched off are one answer. Telling them apart
        // tells a caller which uuids exist.
        $dataSource = DataSource::forIngest($source);

        if ($dataSource === null) {
            return $this->refuse(IngestRefusal::UnknownSource);
        }

        // The raw bytes, read once. Every later check — the size cap, the
        // signature, the body hash — works on this exact string, so there is
        // no window in which the thing verified differs from the thing stored.
        $body = $request->getContent();

        $refusal = $this->guard->refuse($request, $dataSource, $body);

        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        $event = ($this->capture)($request, $dataSource, $body);

        // Queued, not run here. Everything after capture — mapping,
        // validating, deduping, persisting — happens where nobody is waiting
        // on it.
        ProcessIntegrationEvent::start($event->id);

        // 202, not 201: nothing has been created yet, and saying otherwise
        // would be a promise this request has not kept. The event id is
        // returned so a sender can quote it when something goes wrong.
        return response()->json([
            'accepted' => true,
            'event' => $event->uuid,
        ], 202);
    }

    private function refuse(IngestRefusal $refusal): JsonResponse
    {
        return response()->json([
            'accepted' => false,
            'error' => $refusal->value,
            'message' => $refusal->message(),
        ], $refusal->status());
    }
}
