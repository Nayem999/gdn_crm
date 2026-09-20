<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Closure;
use RuntimeException;

/**
 * Which workspace this process is acting for, right now.
 *
 * One object, registered as a singleton, and the only answer to that question.
 * The alternative — each query asking `auth()->user()->tenant_id` — breaks in
 * exactly the places that matter most: a queued job has no authenticated user,
 * a webhook arrives with nobody logged in, a scheduled command runs for every
 * tenant in turn. Those are the paths where a leak would be silent, so they
 * are the paths this is built for.
 *
 * **Nothing infers the tenant.** It is set — by the middleware from the
 * authenticated user, by a webhook from the asset the payload names, by a
 * console command as it walks the list — and a query that runs before it is
 * set fails rather than quietly returning another customer's rows. A default
 * of "no tenant means everything" is how a shared database leaks.
 */
class Tenancy
{
    private ?Tenant $tenant = null;

    /**
     * True while a deliberate piece of cross-tenant work is running.
     *
     * Not a way to avoid setting a tenant: the only legitimate uses are the
     * ones that are *about* every tenant — provisioning, a scheduled command
     * choosing who to run for, the administrator's own list of workspaces.
     */
    private bool $unscoped = false;

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Forget the current tenant.
     *
     * Used between tenants in a loop, and after one finishes: leaving the last
     * one set is how a job that forgot to set its own ends up writing into
     * whoever ran before it.
     */
    public function forget(): void
    {
        $this->tenant = null;
    }

    /**
     * Run something as one tenant, and put back whatever was there before.
     *
     * Restoring matters more than setting. A command looping tenants, a job
     * that touches a second workspace, a test — each leaves the process as it
     * found it, so a failure in the middle cannot strand the next piece of
     * work inside the wrong customer.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function for(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $previouslyUnscoped = $this->unscoped;

        $this->tenant = $tenant;
        $this->unscoped = false;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->unscoped = $previouslyUnscoped;
        }
    }

    /**
     * Run something across every tenant, with the scope deliberately off.
     *
     * Rare and loud on purpose. Provisioning a new workspace, an administrator
     * listing all of them, a migration backfilling — work that is *about* the
     * set of tenants rather than work for one of them.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScope(Closure $callback): mixed
    {
        $previous = $this->tenant;
        $previouslyUnscoped = $this->unscoped;

        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->unscoped = $previouslyUnscoped;
        }
    }

    /**
     * The tenant, or an exception.
     *
     * For the writes: a row created with no tenant is a row nobody can see and
     * every later query has to remember to exclude. Failing here turns that
     * into a stack trace pointing at the code that forgot.
     */
    public function require(): Tenant
    {
        if ($this->tenant === null) {
            throw new RuntimeException(
                'No workspace is set for this request. Tenancy::set() has to be called before touching tenant data — '
                .'see the middleware for web requests, and Tenancy::for() for jobs and commands.'
            );
        }

        return $this->tenant;
    }
}
