<?php

namespace App\Domain\Meta;

use App\Domain\Meta\Enums\MetaChannel;
use Illuminate\Http\Request;

/**
 * The addresses Meta is given, built the one way Meta will accept them.
 *
 * **Always https, and never the scheme of the request this was generated in.**
 * Meta refuses an `http://` redirect URI outright — "Facebook has detected that
 * this app isn't using a secure connection" — and will not deliver a webhook to
 * one either. Yet an application behind a proxy that terminates TLS sees plain
 * HTTP on every request and generates `http://` links accordingly, so
 * `route()` on such a deployment produces exactly the URL Meta rejects. The
 * site is served over HTTPS; only the application's idea of itself is wrong.
 *
 * Forcing the scheme here fixes Meta without waiting for the deployment to be
 * corrected. It does not fix the rest of the application — password reset links
 * and asset URLs are generated the same wrong way — so
 * {@see self::looksMisconfigured()} exists to say so on the screen rather than
 * leaving somebody to discover it one feature at a time.
 *
 * Built from the **configured** address rather than the current request,
 * because these are pasted into Meta and have to be right when an administrator
 * is looking at a development host.
 */
final class MetaUrls
{
    /**
     * Where this installation lives, as Meta must see it.
     *
     * **The address being browsed wins over the configured one.** That is the
     * reverse of the obvious choice, and it is what deployments actually look
     * like: an installation is uploaded with the `.env` it was developed
     * against, so `APP_URL` says `http://localhost:8080` on a site somebody is
     * reading at `https://crm.example.net`. The configured value is a claim;
     * the host in the request is a fact — the administrator reached this screen
     * through it.
     *
     * The configured address is still the fallback, for a queue worker or a
     * console command generating these outside a request.
     */
    public static function base(): string
    {
        $base = self::browsed() ?? self::configured();

        // Forced only where it could matter. On a development address the
        // scheme is left alone: "https://localhost:8080" is not a better answer
        // than the http one — it is an address that serves nothing, printed as
        // though it were real — and Meta cannot use either.
        return $base !== '' && self::isReachableAddress($base)
            ? (string) preg_replace('#^http://#i', 'https://', $base)
            : $base;
    }

    /**
     * The address this request arrived on, when there is one worth trusting.
     *
     * Null outside a request, and null for a host that is not publicly
     * reachable — so browsing a development copy does not overwrite a correctly
     * configured production address in the one place it matters.
     */
    private static function browsed(): ?string
    {
        // No guard for "is this the console": `runningInConsole()` is true
        // inside the test suite as well, which would switch this off exactly
        // where it is being proved. None is needed — a queue worker or an
        // artisan command builds its request from APP_URL, so the host it
        // reports is either the configured one (same answer) or a private one,
        // and a private one fails the check below like any other.
        $host = request()->getSchemeAndHttpHost();

        return self::isReachableAddress($host) ? rtrim($host, '/') : null;
    }

    /**
     * Where Meta returns an administrator after the consent screen.
     *
     * The same string must be sent when the code is exchanged — Meta compares
     * them and refuses a mismatch — which is why both sides ask here rather
     * than calling `route()` twice and hoping they agree.
     */
    public static function callback(): string
    {
        return self::base().route('settings.meta.callback', [], false);
    }

    public static function webhook(MetaChannel $channel): string
    {
        return self::base().route('api.webhooks.meta', $channel->value, false);
    }

    /**
     * Whether Meta could reach this installation at all.
     *
     * A private address is not a deployment mistake — it is an installation
     * that has not been published yet — so this is a different question from
     * {@see self::looksMisconfigured()}.
     */
    public static function isReachable(): bool
    {
        return self::isReachableAddress(self::browsed() ?? self::configured());
    }

    /**
     * Whether Meta could call this particular address.
     */
    private static function isReachableAddress(string $address): bool
    {
        $host = (string) parse_url($address, PHP_URL_HOST);

        if ($host === '') {
            return false;
        }

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_ends_with($host, '.localhost')
            && ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local')
            // A bare IP is reachable in principle, but Meta will not accept a
            // certificate for one.
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }

    /**
     * The address exactly as configured, before any opinion is applied to it.
     */
    private static function configured(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Whether the application's own idea of its address disagrees with the one
     * Meta needs.
     *
     * True when `APP_URL` is http on an installation that is otherwise ready to
     * connect. Meta will work regardless — the scheme is forced above — but
     * every other link this application generates is wrong in the same way, and
     * that is worth saying once rather than discovering through a password
     * reset email that nobody can open.
     */
    public static function looksMisconfigured(): bool
    {
        // Only about the configured value: the browsed address is used in
        // preference for Meta, but everything generated outside a request —
        // a password reset email, a queued notification — still comes from
        // APP_URL, so an http one is still worth reporting.
        return self::isReachable() && str_starts_with(strtolower(self::configured()), 'http://');
    }
}
