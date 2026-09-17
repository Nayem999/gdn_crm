<?php

namespace App\Domain\Meta;

use App\Domain\Meta\Enums\MetaChannel;

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
     */
    public static function base(): string
    {
        $base = self::configured();

        // Forced only where it could matter. On a development address the
        // scheme is left alone: "https://localhost:8080" is not a better answer
        // than the http one — it is an address that serves nothing, printed as
        // though it were real — and Meta cannot use either.
        return $base !== '' && self::isReachable()
            ? (string) preg_replace('#^http://#i', 'https://', $base)
            : $base;
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
        $host = (string) parse_url(self::configured(), PHP_URL_HOST);

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
        return self::isReachable() && str_starts_with(strtolower(self::configured()), 'http://');
    }
}
