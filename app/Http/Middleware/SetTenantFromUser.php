<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every authenticated request act for the signed-in user's workspace.
 *
 * The user is the source, not the subdomain. A URL can be edited; the row
 * cannot, so a customer typing somebody else's subdomain gets their own data
 * rather than an authorisation check that has to be right everywhere. Public
 * routes — a capture form, a chat widget, a Meta webhook — have no user and
 * set their own tenant from the token or the asset the payload names; they are
 * deliberately not served by this.
 *
 * A request that arrives with no user leaves tenancy unset, and every scoped
 * query then matches nothing. That is the intended failure: an empty screen
 * gets reported, another customer's data does not.
 */
class SetTenantFromUser
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Loaded rather than taken from the relation, so a suspended workspace
        // is caught on the request after it is suspended rather than whenever
        // the session happens to refresh.
        $tenant = Tenant::query()->find($user->tenant_id);

        if ($tenant === null) {
            return $next($request);
        }

        if (! $tenant->is_active) {
            // Suspended: the data is still theirs and still here, and they are
            // not served until somebody re-enables it.
            abort(403, 'This workspace is suspended.');
        }

        $this->tenancy->set($tenant);

        return $next($request);
    }
}
