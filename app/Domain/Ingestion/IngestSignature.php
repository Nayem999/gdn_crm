<?php

namespace App\Domain\Ingestion;

/**
 * How we know an inbound request really came from the system we gave the key to.
 *
 * The inbound twin of [[WebhookSignature]], and deliberately the same shape:
 * `t=<unix>,v1=<hex>` over `"{timestamp}.{body}"`, the format Stripe made
 * familiar. The integration at the other end is somebody else's code, and a
 * format they have written against before is one they are less likely to get
 * wrong.
 *
 * **The timestamp is inside the signed string, and that is the whole point.** A
 * signature over the body alone verifies for ever, so anybody who captures one
 * request can send it again next year. With the timestamp signed, anything
 * outside the tolerance is refused and a captured request stops being useful
 * within minutes.
 *
 * Verification happens **before the body is parsed**. An unverified payload is
 * a string of bytes from a stranger; handing it to a JSON parser first means
 * the parser is the thing standing between a stranger and the application.
 */
final class IngestSignature
{
    public const HEADER = 'X-CRM-Signature';

    public const KEY_HEADER = 'X-CRM-Key';

    /**
     * Seconds either side of now that are still acceptable — the brief's
     * ±5 minutes. Wide enough for ordinary clock drift between two servers,
     * narrow enough that a captured request is worthless by the time anybody
     * notices it.
     */
    public const TOLERANCE = 300;

    /**
     * The header a sender builds. Ours, so the tests exercise the real thing
     * rather than a restatement of it, and so the documentation can show it.
     */
    public static function for(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::digest($payload, $secret, $timestamp);
    }

    public static function digest(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * The signed timestamp, or null when the header is missing or malformed.
     *
     * Separated from verification because the two failures need different
     * answers: a signature that does not verify is wrong, a signature that
     * verifies but is hours old is stale, and telling them apart is what lets
     * the sender fix their clock rather than regenerate their key.
     */
    public static function timestampIn(?string $header): ?int
    {
        $parts = self::parse($header);

        return isset($parts['t']) && is_numeric($parts['t']) ? (int) $parts['t'] : null;
    }

    public static function isFresh(?int $timestamp, int $tolerance = self::TOLERANCE): bool
    {
        return $timestamp !== null && abs(time() - $timestamp) <= $tolerance;
    }

    /**
     * Whether the header verifies against any of the secrets offered.
     *
     * A list, because during a rotation both the current and the previous
     * signing secret are legitimate and the sender decides which it used.
     * Freshness is **not** checked here — the caller does that separately, so
     * it can say which of the two went wrong.
     *
     * @param  array<int, string>  $secrets
     */
    public static function verify(?string $header, string $payload, array $secrets): bool
    {
        $parts = self::parse($header);
        $timestamp = self::timestampIn($header);
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || ! is_string($signature) || $signature === '') {
            return false;
        }

        foreach ($secrets as $secret) {
            // Constant time: a timing-sensitive comparison here would let
            // somebody recover a valid signature a byte at a time.
            if (hash_equals(self::digest($payload, $secret, $timestamp), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stable fingerprint of the header, for the replay check.
     *
     * The header rather than the body: a replay presents the *identical* signed
     * request, so this matches exactly and only then. Fingerprinting the body
     * would refuse a second, legitimate delivery carrying the same payload.
     */
    public static function fingerprint(?string $header): ?string
    {
        return $header === null || $header === '' ? null : hash('sha256', $header);
    }

    /**
     * @return array<string, string>
     */
    private static function parse(?string $header): array
    {
        if ($header === null || $header === '') {
            return [];
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);

            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        return $parts;
    }
}
