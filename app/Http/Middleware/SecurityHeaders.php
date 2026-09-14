<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers a browser needs in order to defend the page.
 *
 * Laravel ships none of these, and each one closes a class of attack that the
 * application cannot close on its own — they are instructions to the browser,
 * and only the browser can carry them out.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Stop the browser guessing a content type. Without it, a file served
        // as text/plain that happens to look like HTML is rendered as HTML —
        // which is how an upload becomes stored XSS even when the server
        // labelled it honestly.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // No framing at all: nothing in this application is meant to be
        // embedded, and clickjacking needs a frame. The public capture form is
        // the one thing that *is* embedded, and it is served by its own route
        // outside this middleware.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Send the full URL only to ourselves. A CRM's URLs carry record ids
        // and sometimes a search somebody typed, and neither belongs in an
        // external site's logs.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Powerful features nothing here uses. Named explicitly so an embedded
        // third-party script cannot ask for them on the user's behalf.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'
        );

        // HSTS, but only once the connection is already secure: sending it over
        // plain HTTP is meaningless, and sending it from a local development
        // server pins the developer's browser to https for a site that has no
        // certificate.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
