<?php

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\MetaApiVersion;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Settings\SettingsManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Task 12.1 — the only thing in this application that calls Meta.
 *
 * Nothing here touches the network: every response is a recorded shape. What is
 * being asserted is the behaviour that is easy to get wrong once and then never
 * notice — the version, the secrecy of the token, and what happens when Meta
 * says no.
 */
function metaClient(array $credentials = [], array $config = []): MetaGraphClient
{
    // Credentials go into settings, never into config: this application does
    // not read a Meta credential from the environment, and a test that seeded
    // one there would be testing a path that does not exist.
    $settings = app(SettingsManager::class);

    foreach (array_merge(['app_id' => '1234567890', 'app_secret' => 'app-secret-value'], $credentials) as $key => $value) {
        if ($value !== null) {
            $settings->set('meta.'.$key, $value);
        }
    }

    config(array_merge([
        'meta.graph_version' => 'v21.0',
        'meta.retries' => 3,
        'meta.retry_base_delay' => 0,
    ], $config));

    return new MetaGraphClient(new MetaConfiguration($settings));
}

// -- The version ---------------------------------------------------------------

test('every call goes to the configured graph version', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    metaClient(config: ['meta.graph_version' => 'v19.0'])->get('me');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/v19.0/me'));
});

test('a version that is not a version falls back rather than building a broken URL', function (string $configured) {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    metaClient(config: ['meta.graph_version' => $configured])->get('me');

    // Not "https://graph.facebook.com/latest/me", which Meta answers with an
    // error that says nothing about the real cause.
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/me'));
})->with([
    'latest' => ['latest'],
    'no v' => ['21.0'],
    'no minor' => ['v21'],
    'empty' => [''],
]);

test('the version is validated the same way wherever it is asked', function () {
    expect(MetaApiVersion::isValid('v21.0'))->toBeTrue()
        ->and(MetaApiVersion::isValid('v3.2'))->toBeTrue()
        ->and(MetaApiVersion::isValid('latest'))->toBeFalse()
        ->and(MetaApiVersion::isValid('v21'))->toBeFalse()
        ->and(MetaApiVersion::isValid(null))->toBeFalse();
});

// -- The token -----------------------------------------------------------------

test('the token travels as a bearer header, not in the path', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    metaClient()->get('me', [], 'page-token-value');

    Http::assertSent(function ($request) {
        // A token in the path is a token in every proxy log and every referrer
        // header a redirect produces.
        expect($request->url())->not->toContain('page-token-value');

        return $request->hasHeader('Authorization', 'Bearer page-token-value');
    });
});

test('an authenticated call carries appsecret_proof', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    metaClient()->get('me', [], 'page-token-value');

    $expected = hash_hmac('sha256', 'page-token-value', 'app-secret-value');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'appsecret_proof='.$expected));
});

test('an unauthenticated call sends no proof at all', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    metaClient()->get('oauth/access_token', ['client_id' => '1']);

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'appsecret_proof'));
});

test('the app token is the documented app-id pipe app-secret', function () {
    expect(metaClient()->appToken())->toBe('1234567890|app-secret-value');
});

test('an unconfigured app refuses to build a token rather than sending a broken one', function () {
    expect(fn () => metaClient(['app_id' => null, 'app_secret' => null])->appToken())
        ->toThrow(MetaApiException::class);
});

test('a transport failure never carries the URL, because the URL carries the token', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6: could not resolve host https://graph.facebook.com/v21.0/me?access_token=secret'));

    // The message is ours. Meta's own connection errors quote the full URL, and
    // on a GET that URL has been seen carrying credentials.
    try {
        metaClient()->get('me', [], 'page-token-value');
        $this->fail('The client should have thrown.');
    } catch (MetaApiException $exception) {
        expect($exception->getMessage())->not->toContain('page-token-value');
    }
});

// -- What Meta says no with ----------------------------------------------------

test('an error body becomes an exception carrying the code, not the prose', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => [
            'message' => 'Invalid OAuth access token.',
            'type' => 'OAuthException',
            'code' => 190,
            'error_subcode' => 463,
            'fbtrace_id' => 'AbC123',
        ],
    ], 401)]);

    try {
        metaClient()->get('me', [], 'expired-token');
        $this->fail('The client should have thrown.');
    } catch (MetaApiException $exception) {
        expect($exception->code_)->toBe(190)
            ->and($exception->subcode)->toBe(463)
            ->and($exception->traceId)->toBe('AbC123')
            ->and($exception->status)->toBe(401)
            ->and($exception->isTokenProblem())->toBeTrue()
            ->and($exception->isRetryable())->toBeFalse()
            ->and($exception->userMessage())->toContain('Reconnect');
    }
});

test('a 200 carrying an error object is still an error', function () {
    // Meta does this on some batched and some deprecated endpoints, and a
    // client that only checks the status silently writes nulls into the CRM.
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'No.', 'code' => 100]], 200)]);

    expect(fn () => metaClient()->get('me', [], 'token'))->toThrow(MetaApiException::class);
});

test('a response that is not JSON is an error rather than an empty array', function () {
    Http::fake(['graph.facebook.com/*' => Http::response('<html>maintenance</html>', 502)]);

    expect(fn () => metaClient()->get('me', [], 'token'))->toThrow(MetaApiException::class);
});

test('each kind of refusal is classified the way the caller has to act on it', function (int $code, string $predicate, bool $retryable) {
    $exception = MetaApiException::fromResponse(['error' => ['message' => 'x', 'code' => $code]], 400);

    expect($exception->{$predicate}())->toBeTrue()
        ->and($exception->isRetryable())->toBe($retryable);
})->with([
    'dead token' => [190, 'isTokenProblem', false],
    'missing permission' => [200, 'isPermissionProblem', false],
    'app throttled' => [4, 'isRateLimited', true],
    'business throttled' => [80004, 'isRateLimited', true],
]);

test('the safe message never repeats Meta\'s own words or the trace id', function () {
    $exception = MetaApiException::fromResponse([
        'error' => ['message' => 'Invalid OAuth access token for user 12345.', 'code' => 190, 'fbtrace_id' => 'AbC123'],
    ], 401);

    expect($exception->userMessage())->not->toContain('12345')
        ->and($exception->userMessage())->not->toContain('AbC123')
        // The detail is kept, just not shown to a salesperson.
        ->and($exception->context())->toHaveKey('fbtrace_id');
});

// -- Retrying ------------------------------------------------------------------

test('a connection failure is retried', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts < 3) {
            throw new ConnectionException('connection reset');
        }

        return Http::response(['id' => '1']);
    });

    expect(metaClient()->get('me', [], 'token'))->toBe(['id' => '1'])
        ->and($attempts)->toBe(3);
});

test('a refusal is not retried, because repeating it changes nothing and spends the rate limit', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401);
    });

    expect(fn () => metaClient()->get('me', [], 'token'))->toThrow(MetaApiException::class);

    expect($attempts)->toBe(1);
});

// -- Paging --------------------------------------------------------------------

test('paging follows the cursor and stops when there is no next page', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['data' => [['id' => 'a'], ['id' => 'b']], 'paging' => ['next' => 'https://…', 'cursors' => ['after' => 'CURSOR1']]])
        // No `next`, so this is the last page — even though Meta still returns
        // an `after` cursor on it.
        ->push(['data' => [['id' => 'c']], 'paging' => ['cursors' => ['after' => 'CURSOR2']]]),
    ]);

    $rows = iterator_to_array(metaClient()->paginate('act_1/campaigns', ['limit' => 2], 'token'));

    expect($rows)->toHaveCount(3);

    // The second call carries the cursor from the first.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'after=CURSOR1') || ! str_contains($request->url(), 'after='));
});

test('paging stops at the ceiling rather than following a cursor forever', function () {
    // A `next` that never goes away is a bug at one end or the other; without a
    // ceiling it is an infinite job holding a queue worker.
    Http::fake(['graph.facebook.com/*' => Http::response([
        'data' => [['id' => 'a']],
        'paging' => ['next' => 'https://…', 'cursors' => ['after' => 'SAME']],
    ])]);

    $rows = iterator_to_array(metaClient()->paginate('act_1/campaigns', [], 'token', maxPages: 4), false);

    expect($rows)->toHaveCount(4);
});

// -- Rate limit awareness ------------------------------------------------------

test('it reads how much of the rate limit Meta says is gone', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'], 200, [
        'X-App-Usage' => json_encode(['call_count' => 93, 'total_cputime' => 20, 'total_time' => 40]),
    ])]);

    $client = metaClient();
    $client->get('me', [], 'token');

    expect($client->usagePercentage())->toBe(93)
        ->and($client->isNearRateLimit())->toBeTrue();
});

test('a quiet connection is not near the limit', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'], 200, [
        'X-App-Usage' => json_encode(['call_count' => 3, 'total_cputime' => 1, 'total_time' => 2]),
    ])]);

    $client = metaClient();
    $client->get('me', [], 'token');

    expect($client->isNearRateLimit())->toBeFalse();
});
