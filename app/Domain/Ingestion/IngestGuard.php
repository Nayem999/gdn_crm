<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Enums\IngestRefusal;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Everything that has to be true before a delivery is written down.
 *
 * The checks run **cheapest first, and authentication before parsing**. The
 * body is a string of bytes from a stranger until a signature says otherwise,
 * and handing it to a JSON parser first would make the parser the thing
 * standing between a stranger and the application. Size is checked before any
 * of it is looked at, for the same reason.
 *
 * Nothing here writes an `integration_events` row. A refused request leaves a
 * log line, not a stored payload: storing bodies for anybody who knows a uuid
 * would be an unbounded write primitive handed to whoever guessed one.
 */
final class IngestGuard
{
    /**
     * A quarter of a megabyte by default. Deliveries are records, not files —
     * anything approaching this is a bug at the sending end, and a cap is the
     * difference between that bug being an error and being an outage.
     */
    public const DEFAULT_MAX_PAYLOAD_KB = 256;

    public static function maxPayloadBytes(): int
    {
        return max(1, (int) settings('integrations.max_payload_kb', self::DEFAULT_MAX_PAYLOAD_KB)) * 1024;
    }

    /**
     * Why this delivery should be turned away, or null to accept it.
     */
    public function refuse(Request $request, DataSource $source, string $body): ?IngestRefusal
    {
        $refusal = $this->check($request, $source, $body);

        if ($refusal !== null) {
            $this->record($request, $source, $refusal);
        }

        return $refusal;
    }

    private function check(Request $request, DataSource $source, string $body): ?IngestRefusal
    {
        if (! $this->addressAllowed($request, $source)) {
            return IngestRefusal::AddressNotAllowed;
        }

        if (strlen($body) > self::maxPayloadBytes()) {
            return IngestRefusal::PayloadTooLarge;
        }

        if ($source->requires_key && ($refusal = $this->checkKey($request, $source)) !== null) {
            return $refusal;
        }

        if ($source->requires_signature && ($refusal = $this->checkSignature($request, $source, $body)) !== null) {
            return $refusal;
        }

        return $this->checkReplay($request, $source);
    }

    /**
     * An empty or missing list means "anywhere", which is the honest default:
     * most integrations run somewhere with no fixed address, and a list nobody
     * can fill in correctly is a list people switch off entirely.
     */
    private function addressAllowed(Request $request, DataSource $source): bool
    {
        $allowed = $source->ip_allowlist;

        if (! is_array($allowed) || $allowed === []) {
            return true;
        }

        $ip = (string) $request->ip();

        foreach ($allowed as $entry) {
            if (IpRange::matches($ip, (string) $entry)) {
                return true;
            }
        }

        return false;
    }

    private function checkKey(Request $request, DataSource $source): ?IngestRefusal
    {
        $presented = $request->header(IngestSignature::KEY_HEADER);

        if (! is_string($presented) || $presented === '') {
            return IngestRefusal::MissingKey;
        }

        // Constant time, current key or a grace key — see DataSource::verifyKey.
        return $source->verifyKey($presented) ? null : IngestRefusal::InvalidKey;
    }

    private function checkSignature(Request $request, DataSource $source, string $body): ?IngestRefusal
    {
        $header = $request->header(IngestSignature::HEADER);

        if (! is_string($header) || $header === '') {
            return IngestRefusal::MissingSignature;
        }

        $secrets = $source->signingSecrets();

        if ($secrets === []) {
            // A source that demands a signature but has no secret to check one
            // against cannot authenticate anybody. Refusing is the only honest
            // answer; accepting would be authenticating nothing.
            return IngestRefusal::InvalidSignature;
        }

        $timestamp = IngestSignature::timestampIn($header);

        // A header with no timestamp in it at all is not a stale signature, it
        // is not a signature: telling somebody to check their clock when they
        // have sent nonsense sends them looking in the wrong place.
        if ($timestamp === null) {
            return IngestRefusal::InvalidSignature;
        }

        // Freshness before the digest, so an integrator with a drifting clock
        // is told that rather than being sent to regenerate a key that is
        // perfectly fine.
        if (! IngestSignature::isFresh($timestamp)) {
            return IngestRefusal::StaleTimestamp;
        }

        return IngestSignature::verify($header, $body, $secrets)
            ? null
            : IngestRefusal::InvalidSignature;
    }

    /**
     * The same signed request arriving twice.
     *
     * Matched on the signature's fingerprint, so it catches a captured request
     * sent again and nothing else — two legitimate deliveries carrying the same
     * payload a minute apart are not an attack, and 8.4's idempotency is what
     * stops those becoming two records.
     *
     * Only meaningful for signed requests: without a signature there is nothing
     * that distinguishes a replay from a deliberate re-send.
     */
    private function checkReplay(Request $request, DataSource $source): ?IngestRefusal
    {
        $fingerprint = IngestSignature::fingerprint($request->header(IngestSignature::HEADER));

        if ($fingerprint === null) {
            return null;
        }

        $seen = IntegrationEvent::query()
            ->where('data_source_id', $source->getKey())
            ->where('signature_fingerprint', $fingerprint)
            ->exists();

        return $seen ? IngestRefusal::Replayed : null;
    }

    /**
     * A refused delivery leaves a line in the log with the address it came
     * from — never the key, never the signature, never the body.
     */
    private function record(Request $request, DataSource $source, IngestRefusal $refusal): void
    {
        $context = [
            'source' => $source->uuid,
            'reason' => $refusal->value,
            'ip' => $request->ip(),
        ];

        // An authentication failure is the one worth noticing: somebody is
        // trying keys. The rest are an integration that is not built yet.
        $refusal->isAuthFailure()
            ? Log::warning('Ingest authentication failed', $context)
            : Log::info('Ingest delivery refused', $context);
    }
}
