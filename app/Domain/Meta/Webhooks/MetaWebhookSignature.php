<?php

namespace App\Domain\Meta\Webhooks;

/**
 * Proving a delivery came from Meta.
 *
 * Meta signs the raw body with the app secret and sends the result as
 * `X-Hub-Signature-256: sha256=<hex>`. Without checking it, the endpoint is a
 * public URL that will create leads and conversations for anybody who can guess
 * its shape — and its shape is documented.
 *
 * Three things this gets right that a hand-rolled check usually does not:
 *
 * - **It compares with `hash_equals`.** A `===` on a hex string leaks, through
 *   timing, how much of a guess was right, which is enough to find the rest one
 *   character at a time.
 * - **It signs the bytes that arrived**, not a re-encoded copy. Decoding and
 *   re-encoding JSON changes key order and whitespace, and the signature is over
 *   the original.
 * - **A missing header is a failure, not a pass.** The common bug is treating
 *   "nothing to compare" as "nothing to object to", which turns the whole check
 *   off for anybody who simply omits it.
 */
final class MetaWebhookSignature
{
    public const HEADER = 'X-Hub-Signature-256';

    private const PREFIX = 'sha256=';

    public static function isValid(string $body, ?string $header, ?string $appSecret): bool
    {
        if ($appSecret === null || $appSecret === '' || $header === null || $header === '') {
            return false;
        }

        if (! str_starts_with($header, self::PREFIX)) {
            return false;
        }

        $expected = self::PREFIX.hash_hmac('sha256', $body, $appSecret);

        return hash_equals($expected, $header);
    }

    /**
     * Enough of a signature to recognise it in a log, and not enough to reuse.
     *
     * The delivery log shows this so somebody debugging "was it signed?" can
     * tell two deliveries apart without the log becoming a place signatures are
     * harvested from.
     */
    public static function fingerprint(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }

        return substr(hash('sha256', $header), 0, 12);
    }
}
