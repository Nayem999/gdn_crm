<?php

use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Tenancy;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Every test acts for a workspace, because every real request does.
        // Without this the global tenant scope narrows each query to nothing
        // — which is the right behaviour for an unidentified request and a
        // baffling one for a test that just created the row it cannot find.
        //
        // The workspace the tenants migration created, not a fresh one: it is
        // the one the seeded data and the migrated rows belong to.
        $tenant = Tenant::query()->orderBy('id')->first()
            ?? Tenant::factory()->create(['slug' => 'default']);

        app(Tenancy::class)->set($tenant);

        // The SSRF guard resolves a hostname to decide whether it points inside
        // the network. Left alone, that is a real DNS lookup in every test that
        // touches a webhook — which makes the suite depend on the network being
        // up and quick, and it does fail that way under load.
        //
        // A fixed map instead. Anything not listed resolves to nothing, which
        // is exactly what the "host does not resolve" cases want.
        WebhookTarget::resolveUsing(fn (string $host): array => match ($host) {
            'example.com' => ['93.184.216.34'],
            'hooks.example.com' => ['93.184.216.34'],
            // The systems Phase 8's pull sources fetch from. A pull URL goes
            // through the same guard an outbound webhook does, so a host it
            // cannot resolve is refused before a request is made.
            'their-system.test' => ['93.184.216.34'],
            'broken.test' => ['93.184.216.34'],
            'localhost' => ['127.0.0.1'],
            // A host that resolves to the cloud metadata service. Nothing may
            // call it, including the one check allowed to call an address
            // inside this network (WebhookTarget::refuseSelfCall()).
            'link-local.example.com' => ['169.254.169.254'],
            default => [],
        });
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A lead's one owner, standing in for the `owner_id` column removed when
 * leads moved to multiple, priority-ordered assignees. Reads the same thing
 * `Lead::primaryAssignee()` does — the lowest priority (nulls last), ties
 * broken by whoever was assigned first — so a test written against "the
 * owner" before that change still asks the same question afterwards.
 */
function leadOwnerId(Lead $lead): ?int
{
    return $lead->primaryAssignee()?->id;
}

/**
 * Every user id currently assigned to a lead, in escalation order — for a
 * test asserting on the whole set rather than just the one name a compact
 * display would show.
 *
 * @return array<int, int>
 */
function leadAssigneeIds(Lead $lead): array
{
    return $lead->assignees()->pluck('user_id')->all();
}
