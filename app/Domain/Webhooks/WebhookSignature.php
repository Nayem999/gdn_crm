<?php

namespace App\Domain\Webhooks;

/**
 * How a receiver knows the request really came from us.
 *
 * **The timestamp is signed along with the body**, and sent in the same header.
 * A signature over the body alone is replayable for ever: anyone who captures
 * one request can send it again, tomorrow, and it verifies. With the timestamp
 * inside the signed string, a receiver can refuse anything older than a few
 * minutes and the capture stops being useful.
 *
 * The format is deliberately the one Stripe made familiar —
 * `t=<unix>,v1=<hex>` — because the receiving end is somebody else's code, and
 * a format they have written against before is one they are less likely to get
 * wrong.
 */
final class WebhookSignature
{
    public const HEADER = 'X-CRM-Signature';

    public static function for(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::digest($payload, $secret, $timestamp);
    }

    public static function digest(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * Whether a header verifies — the check a receiver performs, written here
     * so the tests exercise the real thing rather than a restatement of it.
     *
     * @param  int  $tolerance  Seconds either side that are still acceptable.
     */
    public static function verify(string $header, string $payload, string $secret, int $tolerance = 300): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);

            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $timestamp = isset($parts['t']) && is_numeric($parts['t']) ? (int) $parts['t'] : null;
        $signature = $parts['v1'] ?? null;

        if ($timestamp === null || ! is_string($signature)) {
            return false;
        }

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // Constant time: a timing-sensitive comparison here would let somebody
        // recover a valid signature a byte at a time.
        return hash_equals(self::digest($payload, $secret, $timestamp), $signature);
    }
}
