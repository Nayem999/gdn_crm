<?php

use App\Domain\Ingestion\Actions\RevokeSourceSecretAction;
use App\Domain\Ingestion\DTOs\SourceCredentials;
use App\Domain\Ingestion\Enums\IngestRefusal;
use App\Domain\Ingestion\IngestGuard;
use App\Domain\Ingestion\IngestSignature;
use App\Domain\Ingestion\IpRange;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Jobs\ProcessIntegrationEvent;
use App\Livewire\Settings\DataSources;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A source with credentials, and the credentials, so a test can post as it.
 *
 * @return array{0: DataSource, 1: SourceCredentials}
 */
function ingestableSource(array $attributes = []): array
{
    $source = DataSource::factory()->create($attributes);
    $credentials = issueSecret($source);

    return [$source->fresh(), $credentials];
}

/**
 * The headers a well-behaved sender sends.
 *
 * @return array<string, string>
 */
function ingestHeaders(
    SourceCredentials $credentials,
    string $body,
    ?int $timestamp = null,
): array {
    return [
        IngestSignature::KEY_HEADER => $credentials->key,
        IngestSignature::HEADER => IngestSignature::for($body, $credentials->signingSecret, $timestamp ?? time()),
        'Content-Type' => 'application/json',
    ];
}

function postIngest(DataSource $source, string $body, array $headers = [])
{
    return test()->call('POST', '/api/ingest/'.$source->uuid, [], [], [], transform_headers_to_server_vars($headers), $body);
}

/**
 * Laravel's `call()` wants CGI-style server vars, and the header names have to
 * survive the trip — `X-CRM-Key` becomes `HTTP_X_CRM_KEY`.
 *
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function transform_headers_to_server_vars(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
    }

    return $server;
}

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('ingest-test');
});

// -- The happy path ------------------------------------------------------------

test('a properly signed delivery is accepted and written down', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1","title":"Fix the roof"}';

    $response = postIngest($source, $body, ingestHeaders($credentials, $body));

    $response->assertStatus(202)
        ->assertJson(['accepted' => true]);

    $event = IntegrationEvent::query()->firstOrFail();

    // The payload is stored before anything looks at it — that is what makes
    // the log an account of deliveries rather than of successes.
    expect($event->payload)->toBe($body)
        ->and($event->data_source_id)->toBe($source->id)
        ->and($event->body_hash)->toBe(hash('sha256', $body))
        ->and($event->signature_verified)->toBeTrue()
        ->and($response->json('event'))->toBe($event->uuid);
});

test('the bytes are stored exactly as they arrived', function () {
    [$source, $credentials] = ingestableSource();
    // Key order and spacing are part of what the signature covers.
    $body = '{"b":2,   "a":1, "nested":{"x":[1,2,3]}}';

    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(202);

    expect(IntegrationEvent::query()->firstOrFail()->payload)->toBe($body);
});

test('nothing is processed on the request', function () {
    // Faked, because the test queue runs synchronously: without this the job
    // would run inline here and the assertion would describe the queue driver
    // rather than the controller.
    Queue::fake();

    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';

    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(202);

    $event = IntegrationEvent::query()->firstOrFail();

    // 202, not 201: nothing has been created, and saying otherwise would be a
    // promise this request has not kept. The pipeline moves it on from here,
    // where nobody is waiting.
    expect($event->status()->value)->toBe('received')
        ->and($event->record_id)->toBeNull()
        ->and($event->processed_at)->toBeNull();

    Queue::assertPushed(ProcessIntegrationEvent::class);
});

test('a sandbox source still accepts and captures', function () {
    [$source, $credentials] = ingestableSource(['is_sandbox' => true]);
    $body = '{"id":"task-1"}';

    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(202);

    // Captured, and stamped with the decision that applied at the time.
    expect(IntegrationEvent::query()->firstOrFail()->is_sandbox)->toBeTrue();
});

// -- The source itself ----------------------------------------------------------

test('an unknown address is a plain 404', function () {
    $response = test()->postJson('/api/ingest/'.Str::uuid());

    $response->assertStatus(404)
        ->assertJson(['error' => IngestRefusal::UnknownSource->value]);
});

test('a switched-off source answers exactly the same as an unknown one', function () {
    [$source, $credentials] = ingestableSource(['is_active' => false]);
    $body = '{"id":"task-1"}';

    $off = postIngest($source, $body, ingestHeaders($credentials, $body));
    $unknown = test()->postJson('/api/ingest/'.Str::uuid());

    // Telling them apart tells a caller which uuids exist.
    expect($off->status())->toBe(404)
        ->and($off->json())->toBe($unknown->json());

    expect(IntegrationEvent::query()->count())->toBe(0);
});

test('a removed source answers 404', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $source->delete();

    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(404);
});

// -- The key -------------------------------------------------------------------

test('a missing key is refused', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);
    unset($headers[IngestSignature::KEY_HEADER]);

    postIngest($source, $body, $headers)
        ->assertStatus(401)
        ->assertJson(['error' => IngestRefusal::MissingKey->value]);
});

test('a wrong key is refused', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);
    $headers[IngestSignature::KEY_HEADER] = 'crmk_not-the-right-key';

    postIngest($source, $body, $headers)
        ->assertStatus(401)
        ->assertJson(['error' => IngestRefusal::InvalidKey->value]);

    expect(IntegrationEvent::query()->count())->toBe(0);
});

test('a revoked key is refused', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);

    app(RevokeSourceSecretAction::class)($source);

    postIngest($source->fresh(), $body, $headers)->assertStatus(401);
});

test('a key still inside its rotation grace window is accepted', function () {
    [$source, $old] = ingestableSource();
    $body = '{"id":"task-1"}';
    // Sign with the old pair, after a rotation.
    $headers = ingestHeaders($old, $body);

    issueSecret($source->fresh(), graceHours: 24);

    postIngest($source->fresh(), $body, $headers)->assertStatus(202);
});

// -- The signature -------------------------------------------------------------

test('a missing signature is refused', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);
    unset($headers[IngestSignature::HEADER]);

    postIngest($source, $body, $headers)
        ->assertStatus(401)
        ->assertJson(['error' => IngestRefusal::MissingSignature->value]);
});

test('a bad signature is refused', function (string $signature) {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);
    $headers[IngestSignature::HEADER] = str_replace('{t}', (string) time(), $signature);

    postIngest($source, $body, $headers)
        ->assertStatus(401)
        ->assertJson(['error' => IngestRefusal::InvalidSignature->value]);

    expect(IntegrationEvent::query()->count())->toBe(0);
})->with([
    'wrong digest' => ['t={t},v1='.str_repeat('a', 64)],
    'no digest' => ['t={t},v1='],
    'nonsense' => ['not-a-signature'],
]);

test('a signature over a different body does not verify', function () {
    [$source, $credentials] = ingestableSource();
    $signed = '{"id":"task-1"}';
    $sent = '{"id":"task-2"}';

    // The signature is over the bytes, so changing one of them breaks it —
    // which is the whole reason verification happens before parsing.
    postIngest($source, $sent, ingestHeaders($credentials, $signed))
        ->assertStatus(401)
        ->assertJson(['error' => IngestRefusal::InvalidSignature->value]);
});

test('a stale timestamp is refused, and says so', function (int $ageSeconds) {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body, time() - $ageSeconds);

    // Told apart from a bad signature on purpose: an integrator with a drifting
    // clock should fix the clock, not regenerate a key that is perfectly fine.
    postIngest($source, $body, $headers)
        ->assertStatus(401)
        ->assertJson(['error' => IngestRefusal::StaleTimestamp->value]);
})->with([
    'six minutes old' => [360],
    'an hour old' => [3600],
    'six minutes in the future' => [-360],
]);

test('a timestamp inside the window is accepted', function (int $ageSeconds) {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-'.$ageSeconds.'"}';

    postIngest($source, $body, ingestHeaders($credentials, $body, time() - $ageSeconds))
        ->assertStatus(202);
})->with([
    'now' => [0],
    'four minutes old' => [240],
    'four minutes ahead' => [-240],
]);

test('the tolerance is the five minutes the brief asks for', function () {
    expect(IngestSignature::TOLERANCE)->toBe(300);
});

test('a source demanding a signature with no secret to check it against refuses', function () {
    $source = DataSource::factory()->create(['requires_key' => false]);
    $body = '{"id":"task-1"}';

    // Accepting would be authenticating nothing.
    postIngest($source, $body, [IngestSignature::HEADER => 't='.time().',v1='.str_repeat('a', 64)])
        ->assertStatus(401);
});

// -- Replay --------------------------------------------------------------------

test('the same signed request twice is refused the second time', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);

    postIngest($source, $body, $headers)->assertStatus(202);

    postIngest($source, $body, $headers)
        ->assertStatus(409)
        ->assertJson(['error' => IngestRefusal::Replayed->value]);

    // And nothing is written the second time.
    expect(IntegrationEvent::query()->count())->toBe(1);
});

test('the same payload signed again is not a replay', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';

    postIngest($source, $body, ingestHeaders($credentials, $body, time() - 10))->assertStatus(202);

    // Two legitimate deliveries carrying the same payload are not an attack —
    // 8.4's idempotency is what stops those becoming two records.
    postIngest($source, $body, ingestHeaders($credentials, $body, time()))->assertStatus(202);

    expect(IntegrationEvent::query()->count())->toBe(2);
});

test('a replay of one source is not a replay of another', function () {
    [$first, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);

    postIngest($first, $body, $headers)->assertStatus(202);

    // The fingerprint is scoped to the source, so an identical header on a
    // different source is a different delivery.
    [$second, $otherCredentials] = ingestableSource();
    postIngest($second, $body, ingestHeaders($otherCredentials, $body))->assertStatus(202);

    expect(IntegrationEvent::query()->count())->toBe(2);
});

// -- The address allowlist -------------------------------------------------------

test('an address outside the allowlist is refused', function () {
    [$source, $credentials] = ingestableSource(['ip_allowlist' => ['198.51.100.4']]);
    $body = '{"id":"task-1"}';

    postIngest($source, $body, ingestHeaders($credentials, $body))
        ->assertStatus(403)
        ->assertJson(['error' => IngestRefusal::AddressNotAllowed->value]);

    expect(IntegrationEvent::query()->count())->toBe(0);
});

test('an address inside the allowlist is accepted', function (array $allowlist) {
    [$source, $credentials] = ingestableSource(['ip_allowlist' => $allowlist]);
    $body = '{"id":"task-1"}';

    // Laravel's test client reports 127.0.0.1.
    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(202);
})->with([
    'the exact address' => [['127.0.0.1']],
    'a range containing it' => [['127.0.0.0/8']],
    'one of several' => [['198.51.100.4', '127.0.0.1']],
]);

test('an empty allowlist means anywhere', function (mixed $allowlist) {
    [$source, $credentials] = ingestableSource(['ip_allowlist' => $allowlist]);
    $body = '{"id":"task-1"}';

    // A list nobody can keep correct is a list that gets switched off, so the
    // honest default is no list at all.
    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(202);
})->with([
    'null' => [null],
    'empty' => [[]],
]);

test('ranges are matched properly, not by string prefix', function (string $ip, string $entry, bool $expected) {
    expect(IpRange::matches($ip, $entry))->toBe($expected);
})->with([
    'inside a /24' => ['198.51.100.7', '198.51.100.0/24', true],
    'outside a /24' => ['198.51.101.7', '198.51.100.0/24', false],
    'boundary of a /25' => ['198.51.100.127', '198.51.100.0/25', true],
    'past a /25' => ['198.51.100.128', '198.51.100.0/25', false],
    'exact' => ['198.51.100.7', '198.51.100.7', true],
    // "198.51.100.7" starts with "198.51.100.70"? No — but a naive prefix
    // match on the other order would say yes.
    'not a prefix match' => ['198.51.100.70', '198.51.100.7', false],
    'a /32 is one address' => ['198.51.100.7', '198.51.100.7/32', true],
    'ipv6 in range' => ['2001:db8::5', '2001:db8::/32', true],
    'ipv6 outside range' => ['2001:dba::5', '2001:db8::/32', false],
    // An IPv4 address is not inside an IPv6 range, however the bytes line up.
    'families do not mix' => ['127.0.0.1', '::1/128', false],
    'nonsense entry' => ['127.0.0.1', 'not-an-address', false],
]);

test('an allowlist entry that cannot be evaluated is refused by the form', function (string $entry, bool $valid) {
    expect(IpRange::isValid($entry))->toBe($valid);
})->with([
    'address' => ['203.0.113.7', true],
    'cidr' => ['203.0.113.0/24', true],
    'ipv6' => ['2001:db8::/32', true],
    'typo' => ['203.0.113', false],
    'silly prefix' => ['203.0.113.0/64', false],
    'empty' => ['', false],
]);

// -- Size ----------------------------------------------------------------------

test('an oversized payload is refused', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"blob":"'.str_repeat('x', IngestGuard::maxPayloadBytes()).'"}';

    postIngest($source, $body, ingestHeaders($credentials, $body))
        ->assertStatus(413)
        ->assertJson(['error' => IngestRefusal::PayloadTooLarge->value]);

    expect(IntegrationEvent::query()->count())->toBe(0);
});

test('the size cap is checked before the signature, so a huge body is never hashed', function () {
    [$source] = ingestableSource();
    $body = str_repeat('x', IngestGuard::maxPayloadBytes() + 1);

    // No valid signature at all, and the answer is still the size — the cheap
    // check runs first, which is the point of ordering them.
    postIngest($source, $body, ['Content-Type' => 'application/json'])
        ->assertStatus(413);
});

// -- Rate limiting ---------------------------------------------------------------

test('a source is throttled once it goes over its limit', function () {
    [$source, $credentials] = ingestableSource();

    $limit = (int) settings('integrations.ingest_rate_limit_per_minute', 120);
    $sent = 0;

    for ($i = 0; $i <= $limit; $i++) {
        $body = '{"id":"task-'.$i.'"}';
        $response = postIngest($source, $body, ingestHeaders($credentials, $body));

        if ($response->status() === 429) {
            break;
        }

        $sent++;
    }

    expect($sent)->toBe($limit);

    $body = '{"id":"one-too-many"}';
    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(429);
});

test('one busy source does not starve another', function () {
    [$busy, $busyCredentials] = ingestableSource();
    [$quiet, $quietCredentials] = ingestableSource();

    $limit = (int) settings('integrations.ingest_rate_limit_per_minute', 120);

    for ($i = 0; $i <= $limit; $i++) {
        $body = '{"id":"task-'.$i.'"}';
        postIngest($busy, $body, ingestHeaders($busyCredentials, $body));
    }

    // Keyed by source, not by address: the two share an IP here and the quiet
    // one is unaffected.
    $body = '{"id":"still-fine"}';
    postIngest($quiet, $body, ingestHeaders($quietCredentials, $body))->assertStatus(202);
});

// -- What is written down ---------------------------------------------------------

test('the key header is never kept on the event', function () {
    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';

    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(202);

    $event = IntegrationEvent::query()->firstOrFail();
    $stored = json_encode($event->headers);

    // The log must not become the one place a credential is written down.
    expect($stored)->not->toContain($credentials->key)
        ->and($stored)->not->toContain($credentials->signingSecret)
        ->and(array_keys($event->headers ?? []))->not->toContain(strtolower(IngestSignature::KEY_HEADER));
});

test('a refused delivery is logged with its address, and stores nothing', function () {
    $lines = [];
    Log::listen(function ($message) use (&$lines) {
        $lines[] = $message->level.' '.$message->message.' '.json_encode($message->context);
    });

    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';
    $headers = ingestHeaders($credentials, $body);
    $headers[IngestSignature::KEY_HEADER] = 'crmk_wrong';

    postIngest($source, $body, $headers)->assertStatus(401);

    $written = implode("\n", $lines);

    expect($written)->toContain('Ingest authentication failed')
        ->and($written)->toContain('127.0.0.1')
        ->and($written)->toContain($source->uuid)
        // Never the key that was tried, and never the body.
        ->and($written)->not->toContain('crmk_wrong')
        ->and($written)->not->toContain($body)
        // Storing bodies for anybody who knows a uuid would be an unbounded
        // write primitive handed to whoever guessed one.
        ->and(IntegrationEvent::query()->count())->toBe(0);
});

test('every refusal maps to exactly one status', function (string $case, int $status) {
    expect(IngestRefusal::from($case)->status())->toBe($status);
})->with([
    ['unknown_source', 404],
    ['address_not_allowed', 403],
    ['payload_too_large', 413],
    ['missing_key', 401],
    ['invalid_key', 401],
    ['missing_signature', 401],
    ['invalid_signature', 401],
    ['stale_timestamp', 401],
    ['replayed', 409],
]);

// -- The two authentication modes ---------------------------------------------------

test('a source can check a key only', function () {
    [$source, $credentials] = ingestableSource(['requires_signature' => false]);
    $body = '{"id":"task-1"}';

    postIngest($source, $body, [IngestSignature::KEY_HEADER => $credentials->key])->assertStatus(202);
});

test('a source can check a signature only', function () {
    [$source, $credentials] = ingestableSource(['requires_key' => false]);
    $body = '{"id":"task-1"}';

    postIngest($source, $body, [
        IngestSignature::HEADER => IngestSignature::for($body, $credentials->signingSecret, time()),
    ])->assertStatus(202);
});

test('the form refuses a source that would check nothing', function () {
    Livewire\Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Wide open')
        ->set('target_module', 'leads')
        ->set('requires_key', false)
        ->set('requires_signature', false)
        ->call('save')
        ->assertHasErrors(['requires_key']);

    expect(DataSource::query()->count())->toBe(0);
});

test('the form refuses an allowlist entry it cannot evaluate', function () {
    Livewire\Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Typo')
        ->set('target_module', 'leads')
        ->set('ip_allowlist', "203.0.113.7\nnot-an-address")
        ->call('save')
        ->assertHasErrors(['ip_allowlist']);

    expect(DataSource::query()->count())->toBe(0);
});

test('the form stores an allowlist as a list, however it was pasted', function () {
    Livewire\Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Allowlisted')
        ->set('target_module', 'leads')
        ->set('ip_allowlist', "203.0.113.7\n\n  198.51.100.0/24  \n")
        ->call('save')
        ->assertHasNoErrors();

    expect(DataSource::query()->firstOrFail()->ip_allowlist)->toBe(['203.0.113.7', '198.51.100.0/24']);
});

test('the delivery is logged with what the sender called it', function () {
    [$source, $credentials] = ingestableSource();
    $body = json_encode(['event' => 'invoice.paid', 'id' => 42]);

    postIngest($source, $body, ingestHeaders($credentials, $body))->assertSuccessful();

    // Written when the bytes are written, not when they are processed: a
    // delivery the pipeline never gets to is exactly the one somebody has to
    // find in the log.
    expect(IntegrationEvent::query()->latest('id')->first()->event)->toBe('invoice.paid');
});

test('a sender that names its event in a header is logged by that name', function () {
    [$source, $credentials] = ingestableSource();
    $body = json_encode(['ref' => 'refs/heads/master']);

    postIngest($source, $body, ingestHeaders($credentials, $body) + ['X-GitHub-Event' => 'push'])
        ->assertSuccessful();

    expect(IntegrationEvent::query()->latest('id')->first()->event)->toBe('push');
});
