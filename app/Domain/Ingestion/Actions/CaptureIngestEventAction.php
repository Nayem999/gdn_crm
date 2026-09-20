<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\IngestSignature;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Ingestion\WebhookEventName;
use Illuminate\Http\Request;

/**
 * Writes down what arrived, before anything looks at it.
 *
 * This is the whole of the endpoint's work. Everything else — mapping,
 * transforming, deduplicating, persisting — happens on the queue, because a
 * sender waiting on our database is a sender that times out and retries, and a
 * retry storm is how an integration takes an application down.
 *
 * The row exists **before** the response is written, so an event that later
 * crashes the processor still leaves an account of having been received. That
 * is what makes the log in 8.9 trustworthy: it records deliveries, not
 * successes.
 */
class CaptureIngestEventAction
{
    public function __invoke(Request $request, DataSource $source, string $body): IntegrationEvent
    {
        $event = new IntegrationEvent;

        $event->forceFill([
            'data_source_id' => $source->getKey(),
            // Exactly the bytes that arrived. Not re-encoded, not normalised,
            // not parsed — the signature was computed over these, and a replay
            // has to send the same thing.
            'payload' => $body,
            // What the sender called it, read here rather than in the pipeline:
            // a delivery the processor never gets to is exactly the one
            // somebody needs to identify in the log.
            'event' => WebhookEventName::for($request, $body),
            'body_hash' => hash('sha256', $body),
            'signature_fingerprint' => IngestSignature::fingerprint($request->header(IngestSignature::HEADER)),
            'headers' => $this->headers($request),
            'ip_address' => $request->ip(),
            // True only because the guard has already run and let this through.
            'signature_verified' => (bool) $source->requires_signature,
            // Copied from the source as it is right now: somebody takes a
            // source out of sandbox the moment it works, and every event before
            // that would otherwise read as though it had written a record.
            'is_sandbox' => (bool) $source->is_sandbox,
            'received_at' => now(),
        ])->save();

        $this->captureSample($source, $body);

        return $event;
    }

    /**
     * Keep this body as the source's sample, if it is listening for one.
     *
     * Done here rather than in the pipeline so a payload the processor later
     * chokes on is still the one somebody can build a mapping against — the
     * payloads worth capturing are exactly the ones that do not work yet.
     *
     * Listening clears itself on the first catch: the point is one real
     * example, and leaving it on would replace that example with whatever
     * arrived next while somebody was still reading it.
     */
    private function captureSample(DataSource $source, string $body): void
    {
        if (! $source->isListening()) {
            return;
        }

        $source->forceFill([
            'sample_payload' => $body,
            'sample_captured_at' => now(),
            'listening_until' => null,
        ])->save();
    }

    /**
     * The headers worth keeping, and only those.
     *
     * An allowlist rather than the lot: request headers carry cookies,
     * authorization lines and whatever else a proxy added, and this row is read
     * by anybody with `integrations.view`. The key header is **never** kept —
     * the log would become the one place a credential is written down.
     *
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $kept = [];

        foreach (['content-type', 'content-length', 'user-agent', IngestSignature::HEADER] as $name) {
            $value = $request->header($name);

            if (is_string($value) && $value !== '') {
                $kept[strtolower($name)] = $value;
            }
        }

        return $kept;
    }
}
