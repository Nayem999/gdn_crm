<?php

namespace App\Domain\Workflows\Webhooks;

/**
 * Whether a URL is somewhere this application may be told to send a request.
 *
 * A workflow action that posts to a configured address is a **server-side
 * request forgery primitive** unless something stops it: anybody who can write
 * a workflow can otherwise make the application fetch `http://169.254.169.254/`
 * and hand back the cloud instance's credentials, or reach a database, an admin
 * panel or a queue dashboard that is only listening on the private network.
 *
 * So an address is refused unless it is:
 *
 * - http or https, and nothing else — no `file://`, no `gopher://`
 * - a hostname that resolves, and resolves **only** to public addresses
 *
 * Every resolved address is checked, not just the first: a host with one public
 * and one loopback record would otherwise pass and then connect to whichever
 * the resolver felt like.
 *
 * **What this does not stop**, stated plainly rather than implied: DNS
 * rebinding. The check resolves the name, and the HTTP client resolves it again
 * when it connects; a name that answers differently between the two gets
 * through. Closing that needs the connection pinned to the address that was
 * checked, which is a transport-level concern and belongs with 7.10's outbound
 * webhook work, where signing and retries live too.
 */
final class WebhookTarget
{
    /**
     * The ranges nothing outside this machine should be able to make it reach.
     *
     * Link-local (169.254/16) is first because it is the one with teeth: it
     * carries the cloud metadata service on every major provider.
     */
    private const BLOCKED = [
        '169.254.0.0/16',   // link-local, and cloud instance metadata
        '127.0.0.0/8',      // loopback
        '10.0.0.0/8',       // private
        '172.16.0.0/12',    // private
        '192.168.0.0/16',   // private
        '0.0.0.0/8',        // "this network"
        '100.64.0.0/10',    // carrier-grade NAT
        '192.0.0.0/24',     // IETF protocol assignments
        '198.18.0.0/15',    // benchmarking
        '240.0.0.0/4',      // reserved
    ];

    /**
     * Why this address is refused, or null when it is allowed.
     */
    public static function refuse(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'That is not a URL a workflow can call.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'A webhook must be http or https.';
        }

        $host = $parts['host'];
        $addresses = self::resolve($host);

        if ($addresses === []) {
            return $host.' does not resolve.';
        }

        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                return $host.' resolves to an address inside this network.';
            }
        }

        return null;
    }

    public static function allows(string $url): bool
    {
        return self::refuse($url) === null;
    }

    /**
     * A stand-in for the system resolver.
     *
     * The guard has to resolve a name to decide whether it points inside the
     * network, and a real lookup makes every test that exercises it depend on
     * DNS being reachable and quick. That is not a hypothetical: the suite ran
     * green for weeks and then failed on four webhook tests at once, under
     * load, because one lookup timed out.
     *
     * Production leaves this null and resolves for real.
     *
     * @var null|callable(string): array<int, string>
     */
    private static $resolver = null;

    /**
     * @param  null|callable(string): array<int, string>  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        // A literal address needs no lookup, and passing one to the resolver
        // would happily "resolve" it to itself anyway.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private static function isBlocked(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // PHP's own reserved-range filter covers IPv6 loopback (::1) and
            // unique-local (fc00::/7); there is no point restating them.
            return filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        foreach (self::BLOCKED as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ip = ip2long($address);
        $net = ip2long($subnet);

        if ($ip === false || $net === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ip & $mask) === ($net & $mask);
    }
}
