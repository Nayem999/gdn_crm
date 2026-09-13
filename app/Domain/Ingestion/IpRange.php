<?php

namespace App\Domain\Ingestion;

/**
 * Whether an address falls inside an allowlist entry.
 *
 * Entries are either a plain address (`203.0.113.7`) or CIDR notation
 * (`203.0.113.0/24`). CIDR because integrations run behind load balancers and
 * "the address it comes from" is usually a range somebody was given rather than
 * a single number they can promise.
 *
 * Hand-rolled rather than pulled in: it is twenty lines, and a dependency for
 * twenty lines is a dependency to keep patched for ever.
 */
final class IpRange
{
    public static function matches(string $ip, string $entry): bool
    {
        $entry = trim($entry);

        if ($entry === '' || $ip === '') {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return hash_equals($entry, $ip);
        }

        [$subnet, $bits] = explode('/', $entry, 2);

        if (! is_numeric($bits)) {
            return false;
        }

        $packedIp = @inet_pton($ip);
        $packedSubnet = @inet_pton($subnet);

        // Different families never match: an IPv4 address is not inside an IPv6
        // range, and comparing their packed forms byte by byte would sometimes
        // say it was.
        if ($packedIp === false || $packedSubnet === false || strlen($packedIp) !== strlen($packedSubnet)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($packedIp) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        return hash_equals(
            self::mask($packedSubnet, $bits),
            self::mask($packedIp, $bits)
        );
    }

    /**
     * Whether a list entry is something this class can actually evaluate, so a
     * form can refuse a typo rather than storing a rule that silently matches
     * nothing.
     */
    public static function isValid(string $entry): bool
    {
        $entry = trim($entry);

        if ($entry === '') {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        $packed = @inet_pton($subnet);

        if ($packed === false || ! is_numeric($bits)) {
            return false;
        }

        $bits = (int) $bits;

        return $bits >= 0 && $bits <= strlen($packed) * 8;
    }

    /**
     * The address with everything below the prefix length zeroed out.
     */
    private static function mask(string $packed, int $bits): string
    {
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        $masked = substr($packed, 0, $bytes);

        if ($remainder > 0 && strlen($packed) > $bytes) {
            $masked .= chr(ord($packed[$bytes]) & (0xFF << (8 - $remainder)) & 0xFF);
        }

        return $masked;
    }
}
