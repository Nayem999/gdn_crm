<?php

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

function something()
{
    // ..
}
