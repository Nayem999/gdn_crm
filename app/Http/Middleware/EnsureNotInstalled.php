<?php

namespace App\Http\Middleware;

use App\Domain\Install\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shuts the installation wizard the moment there is anything to protect.
 *
 * The wizard is the one unauthenticated screen that creates an administrator,
 * so it must be unreachable for the entire life of the installation after the
 * first run — not merely hidden, and not merely unlinked.
 */
class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::isComplete()) {
            // To the login screen rather than a 404: somebody who lands here
            // from a bookmark or an old tutorial wants to sign in, and there is
            // nothing secret about an installed application being installed.
            return redirect()->route('login');
        }

        return $next($request);
    }
}
