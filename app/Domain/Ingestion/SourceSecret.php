<?php

namespace App\Domain\Ingestion;

use Illuminate\Support\Str;

/**
 * How an inbound source's credentials are minted and checked.
 *
 * Two different things, stored two different ways, because the maths demands
 * it:
 *
 * - **The key** (`X-CRM-Key`) is *presented* by the caller, so it is stored as
 *   a hash and compared. It is never recoverable, and a database backup carries
 *   nothing usable away.
 * - **The signing secret** is an HMAC key, so verifying a signature means
 *   recomputing it — which needs the value itself. It is therefore encrypted at
 *   rest rather than hashed. That is not a weaker choice made for convenience:
 *   a hash simply cannot do the job.
 *
 * The hash is SHA-256, not bcrypt. The secret is 48 characters of CSPRNG
 * output, so there is nothing to brute-force and no need for a work factor —
 * and this is checked on **every delivery**, where a deliberate work factor
 * would be a denial-of-service lever pointed at ourselves. This is the same
 * reasoning Sanctum applies to API tokens.
 */
final class SourceSecret
{
    /**
     * Long enough that the hash is the only thing anybody could attack.
     */
    public const LENGTH = 48;

    /**
     * Prefixes so a leaked credential is recognisable.
     *
     * Secret-scanning tools — GitHub push protection among them — match on
     * shapes like this, and a key pasted into a public repository is found by
     * the scanner rather than by whoever went looking for one.
     */
    public const KEY_PREFIX = 'crmk_';

    public const SIGNING_PREFIX = 'crms_';

    public static function generateKey(): string
    {
        return self::KEY_PREFIX.Str::random(self::LENGTH);
    }

    public static function generateSigningSecret(): string
    {
        return self::SIGNING_PREFIX.Str::random(self::LENGTH);
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * A constant-time comparison of a presented secret against a stored hash.
     *
     * `hash_equals`, never `===`: string comparison returns as soon as two
     * bytes differ, and the time that takes is a measurement of how much of the
     * secret was right. Null is a miss rather than a match, so a source with no
     * key issued cannot be authenticated by sending nothing.
     */
    public static function matches(string $presented, ?string $storedHash): bool
    {
        if ($storedHash === null || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($presented));
    }

    /**
     * The tail of a key, so the screen can say which one is in use.
     *
     * Four characters of a 48-character random string identifies it to somebody
     * holding it and is worth nothing to anybody who is not — the same trade
     * every card and key management screen makes.
     */
    public static function hint(string $secret): string
    {
        return substr($secret, -4);
    }
}
