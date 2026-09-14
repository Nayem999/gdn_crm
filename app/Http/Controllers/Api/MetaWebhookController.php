<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ingestion\Actions\CaptureIngestEventAction;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\Webhooks\MetaEventKey;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Domain\Meta\Webhooks\MetaWebhookSignature;
use App\Jobs\ProcessIntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * `GET|POST /api/webhooks/meta/{channel}` — where Meta delivers.
 *
 * Deliberately short, for the same reason the ingest endpoint is: Meta gives a
 * webhook a few seconds and retries anything slower, and a retry of a lead-ads
 * event we were halfway through processing is a duplicate lead. So this verifies
 * the signature, writes the body down, answers, and does the work on the queue.
 *
 * The GET is Meta's subscription handshake. It is answered with the challenge
 * **only** when the verify token matches — an endpoint that echoed any challenge
 * would let somebody else point their app's webhook at our URL and watch our
 * customers' messages arrive in their delivery log.
 *
 * The payload is data. Nothing in it names a column, a class or a module: the
 * channel in the URL decides what handles it, and the channel is matched against
 * an enum.
 */
class MetaWebhookController
{
    public function __construct(
        private readonly MetaConfiguration $config,
        private readonly CaptureIngestEventAction $capture,
    ) {}

    /**
     * Meta's subscription handshake.
     */
    public function verify(Request $request, string $channel): Response
    {
        if (MetaChannel::tryFrom($channel) === null) {
            return response('Unknown channel.', 404);
        }

        $token = $this->config->verifyToken();
        $sent = $request->query('hub_verify_token');

        // hash_equals on both: a mismatch must not leak, through timing, how
        // much of a guess was right.
        $matches = $token !== null
            && is_string($sent)
            && hash_equals($token, $sent);

        if ($request->query('hub_mode') !== 'subscribe' || ! $matches) {
            Log::warning('A Meta webhook verification was refused.', [
                'channel' => $channel,
                'ip' => $request->ip(),
            ]);

            return response('Verification failed.', 403);
        }

        $challenge = $request->query('hub_challenge');

        // Echoed verbatim and as plain text, which is what Meta compares.
        return response(is_string($challenge) ? $challenge : '', 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * A delivery.
     */
    public function receive(Request $request, string $channel): JsonResponse
    {
        $meta = MetaChannel::tryFrom($channel);

        if ($meta === null) {
            return response()->json(['status' => 'ignored'], 404);
        }

        // The raw bytes, read once: the signature is over exactly these, and a
        // re-encoded copy would not match.
        $body = $request->getContent();

        if (! MetaWebhookSignature::isValid($body, $request->header(MetaWebhookSignature::HEADER), $this->config->appSecret())) {
            Log::warning('A Meta webhook arrived with a signature that did not verify.', [
                'channel' => $channel,
                'ip' => $request->ip(),
                'fingerprint' => MetaWebhookSignature::fingerprint($request->header(MetaWebhookSignature::HEADER)),
            ]);

            // 403 rather than 401: there is nothing to authenticate with, and a
            // 401 invites a retry that will fail identically.
            return response()->json(['status' => 'refused'], 403);
        }

        $source = MetaSources::for($meta);
        $key = MetaEventKey::for($meta, $body);

        // Meta retries a delivery it did not hear a 200 for, and its retries
        // carry the same ids. Answering 200 to a repeat — rather than
        // processing it again — is what stops one lead becoming three.
        if ($key !== null && IntegrationEvent::query()
            ->where('data_source_id', $source->id)
            ->where('external_id', $key)
            ->exists()) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $event = ($this->capture)($request, $source, $body);

        if ($key !== null) {
            $event->forceFill(['external_id' => $key])->save();
        }

        ProcessIntegrationEvent::dispatch($event->id);

        // 200, promptly. Meta reads anything else as a failure and queues a
        // retry, and its retry schedule outlives most outages.
        return response()->json(['status' => 'received'], 200);
    }
}
