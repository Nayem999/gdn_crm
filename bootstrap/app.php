<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a proxy that terminates TLS — nginx, a load balancer,
        // Cloudflare — every request reaches PHP as plain HTTP, so Laravel
        // builds `http://` links for a site that is served over https. That is
        // not cosmetic: Meta refuses an insecure OAuth redirect outright, and a
        // password reset email sends somebody to an address their browser
        // warns them about.
        //
        // Configured rather than assumed. Trusting every proxy is right behind
        // a load balancer whose addresses change and wrong on a host reachable
        // directly, where it would let a client claim any address it liked, so
        // an installation says which — and one that says nothing trusts
        // nothing, exactly as before.
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }

        // Binds each session to the user's current password hash, so changing a
        // password (or logging out other devices) invalidates sibling sessions.
        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        // The headers a browser needs to defend the page. Appended to every
        // response rather than to the web group alone, so an API response and a
        // file download carry them too.
        $middleware->append(SecurityHeaders::class);

        // Sanctum's ability guards, used to make a read-only API key a real
        // thing rather than a promise on a settings screen.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // The public capture form is embedded in an iframe on other people's
        // sites, where the session cookie is a third-party cookie and browsers
        // increasingly refuse to send it — so a CSRF token could not be checked
        // even when one was issued. What stands in for it: the route is a
        // random 32-character token, the only thing it can do is create a lead
        // owned by whoever the form names, and it is rate limited, honeypotted
        // and timed.
        //
        // Providers reporting a bounce are not carrying a session cookie, so
        // there is no token to check. What stands in for it: the path carries an
        // unguessable token, the provider's own signature is verified where one
        // exists, and the only thing a request can do is attach an event to a
        // message this application already sent.
        //
        // Nothing else in the application is exempt.
        $middleware->validateCsrfTokens(except: [
            'f/*',
            'webhooks/*',
            // The chat widget is the capture form's problem again: embedded on
            // somebody else's site, no usable session, an unguessable token in
            // the path and a rate limit on the route.
            'c/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Every logged exception carries who it happened to and which request
         * it was.
         *
         * Without this a production log is a list of stack traces with no way
         * to tell one person's broken afternoon from a passing blip, and the
         * first question anybody asks — "who saw this?" — is unanswerable.
         *
         * The user's id, not their name or address: a log is the wrong place
         * for personal data, and an id is enough to find them.
         */
        $exceptions->context(fn (): array => array_filter([
            'user_id' => auth()->id(),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'ip' => request()->ip(),
        ]));

        /*
         * An external error tracker plugs in here — `$exceptions->reportable()`
         * handing the exception to Sentry, Flare or whichever service the
         * company uses. None is wired up because adding one is a dependency
         * decision, and the stack for this application is fixed.
         */
    })->create();
