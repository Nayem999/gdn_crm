<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Ingestion\Actions\IssueSourceSecretAction;
use App\Domain\Ingestion\Actions\RevokeSourceSecretAction;
use App\Domain\Ingestion\DTOs\SourceCredentials;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\SourceSecret;
use App\Domain\Settings\SettingsRegistry;
use App\Livewire\Settings\DataSources;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity as AuditEntry;

function issueSecret(DataSource $source, ?int $graceHours = null): SourceCredentials
{
    return app(IssueSourceSecretAction::class)($source, $graceHours);
}

function ingestionSecretAdmin(): User
{
    return ingestionUser(['integrations.view', 'integrations.manage', 'integrations.secrets']);
}

beforeEach(function () {
    Cache::flush();
});

// -- Generation ----------------------------------------------------------------

test('issuing a key returns it once and stores only a hash', function () {
    $source = DataSource::factory()->create();

    $credentials = issueSecret($source);
    $source->refresh();

    expect($credentials->key)->toStartWith(SourceSecret::KEY_PREFIX)
        ->and(strlen($credentials->key))->toBe(strlen(SourceSecret::KEY_PREFIX) + SourceSecret::LENGTH)
        // What is stored is a hash of it, and nothing anywhere holds the value.
        ->and($source->secret_hash)->toBe(hash('sha256', $credentials->key))
        ->and($source->secret_hash)->not->toBe($credentials->key)
        ->and($source->hasSecret())->toBeTrue();
});

test('the stored key cannot be turned back into the key', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    // A hash, so there is nothing to reverse — the row does not contain the
    // secret in any form.
    $row = DB::table('data_sources')->where('id', $source->id)->first();

    expect((string) $row->secret_hash)->not->toContain($credentials->key)
        ->and((string) $row->secret_hint)->not->toBe($credentials->key)
        ->and(strlen((string) $row->secret_hash))->toBe(64);
});

test('the signing secret is encrypted at rest, not stored in the clear', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    $row = DB::table('data_sources')->where('id', $source->id)->first();

    // Encrypted rather than hashed, because verifying an HMAC means recomputing
    // it — a hash could not do the job. But a database backup still carries
    // nothing readable away.
    expect((string) $row->signing_secret)->not->toContain($credentials->signingSecret)
        ->and($source->fresh()->signing_secret)->toBe($credentials->signingSecret);
});

test('the hint is four characters of the key and nothing more', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    expect($source->fresh()->secret_hint)->toBe(substr($credentials->key, -4))
        ->and(strlen((string) $source->fresh()->secret_hint))->toBe(4);
});

test('two sources never receive the same key', function () {
    $first = issueSecret(DataSource::factory()->create());
    $second = issueSecret(DataSource::factory()->create());

    expect($first->key)->not->toBe($second->key)
        ->and($first->signingSecret)->not->toBe($second->signingSecret)
        // And the key is not the signing secret, which would make one leak two.
        ->and($first->key)->not->toBe($first->signingSecret);
});

// -- Verification --------------------------------------------------------------

test('a valid key is accepted', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    expect($source->fresh()->verifyKey($credentials->key))->toBeTrue();
});

test('a wrong key is rejected', function (string $presented) {
    $source = DataSource::factory()->create();
    issueSecret($source);

    expect($source->fresh()->verifyKey($presented))->toBeFalse();
})->with([
    'a different key' => [SourceSecret::KEY_PREFIX.'0000000000000000000000000000000000000000000000000'],
    'empty' => [''],
    'the prefix alone' => [SourceSecret::KEY_PREFIX],
]);

test('a null key is rejected', function () {
    $source = DataSource::factory()->create();
    issueSecret($source);

    expect($source->fresh()->verifyKey(null))->toBeFalse();
});

test('a source with no key issued cannot be authenticated by sending nothing', function () {
    $source = DataSource::factory()->create();

    // The stored hash is null, and a null hash is a miss rather than a match —
    // otherwise "no key" would mean "any key".
    expect($source->verifyKey(null))->toBeFalse()
        ->and($source->verifyKey(''))->toBeFalse()
        ->and($source->verifyKey('anything'))->toBeFalse()
        ->and(SourceSecret::matches('anything', null))->toBeFalse();
});

test('the comparison is constant time and covers the whole key', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);
    $source->refresh();

    // hash_equals, never ===: string comparison returns as soon as two bytes
    // differ, and how long that takes measures how much of the secret was
    // right. The call is what makes it constant time.
    $body = file_get_contents((new ReflectionClass(SourceSecret::class))->getFileName());
    expect($body)->toContain('hash_equals(');

    // And behaviourally: a key wrong in its first byte and one wrong only in
    // its last are both rejected, so nothing is matching on a prefix.
    $wrongFirst = 'X'.substr($credentials->key, 1);
    $wrongLast = substr($credentials->key, 0, -1).'X';
    $truncated = substr($credentials->key, 0, -1);

    expect($source->verifyKey($wrongFirst))->toBeFalse()
        ->and($source->verifyKey($wrongLast))->toBeFalse()
        ->and($source->verifyKey($truncated))->toBeFalse()
        ->and($source->verifyKey($credentials->key.'X'))->toBeFalse()
        ->and($source->verifyKey($credentials->key))->toBeTrue();
});

// -- Rotation ------------------------------------------------------------------

test('rotating issues a new key and keeps the old one working for the grace window', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $source = DataSource::factory()->create();
    $old = issueSecret($source);

    $new = issueSecret($source->fresh(), graceHours: 24);
    $source->refresh();

    expect($source->verifyKey($new->key))->toBeTrue()
        // The integration at the other end has to redeploy; a rotation that
        // stopped the old key at once would mean nobody ever rotated anything.
        ->and($source->verifyKey($old->key))->toBeTrue()
        ->and($source->isInGrace())->toBeTrue()
        ->and($source->graceEndsAt()?->format('Y-m-d H:i'))->toBe('2026-09-14 09:00');
});

test('the old key stops working when the grace window closes', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $source = DataSource::factory()->create();
    $old = issueSecret($source);
    $new = issueSecret($source->fresh(), graceHours: 24);

    Carbon::setTestNow('2026-09-14 08:59:00');
    expect($source->fresh()->verifyKey($old->key))->toBeTrue();

    // On the clock, not on a sweep somebody has to remember to run.
    Carbon::setTestNow('2026-09-14 09:01:00');
    expect($source->fresh()->verifyKey($old->key))->toBeFalse()
        ->and($source->fresh()->verifyKey($new->key))->toBeTrue()
        ->and($source->fresh()->isInGrace())->toBeFalse();
});

test('a rotation with no grace drops the old key immediately', function () {
    $source = DataSource::factory()->create();
    $old = issueSecret($source);

    // What somebody rotating because a key leaked actually wants.
    $new = issueSecret($source->fresh(), graceHours: 0);
    $source->refresh();

    expect($source->verifyKey($old->key))->toBeFalse()
        ->and($source->verifyKey($new->key))->toBeTrue()
        ->and($source->previous_secret_hash)->toBeNull();
});

test('only one key back is kept — rotating twice retires the oldest', function () {
    $source = DataSource::factory()->create();
    $first = issueSecret($source);
    $second = issueSecret($source->fresh(), graceHours: 24);
    $third = issueSecret($source->fresh(), graceHours: 24);
    $source->refresh();

    expect($source->verifyKey($third->key))->toBeTrue()
        ->and($source->verifyKey($second->key))->toBeTrue()
        // Two rotations in a day does not leave three keys alive.
        ->and($source->verifyKey($first->key))->toBeFalse();
});

test('the grace window is measured from the rotation, not from the setting', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $source = DataSource::factory()->create();
    issueSecret($source);
    $old = $source->fresh();
    issueSecret($old, graceHours: 1);

    // Stored as an expiry stamp, so changing the setting afterwards cannot
    // silently extend a rotation that already happened.
    expect($source->fresh()->previous_secret_expires_at?->format('Y-m-d H:i'))->toBe('2026-09-13 10:00');
});

test('the grace window comes from a setting when nothing is passed', function () {
    $source = DataSource::factory()->create();
    issueSecret($source);

    Carbon::setTestNow('2026-09-13 09:00:00');
    issueSecret($source->fresh());

    expect($source->fresh()->previous_secret_expires_at?->format('Y-m-d H:i'))
        ->toBe(Carbon::parse('2026-09-13 09:00:00')->addHours(DataSource::rotationGraceHours())->format('Y-m-d H:i'));
});

test('the grace setting is declared in the registry, as a number field', function () {
    $fields = SettingsRegistry::fields('integrations');

    // A select whose options are numbers must be an Integer field, or the
    // string rule rejects the very options it offers — see .ai/rules/settings.md.
    expect($fields)->toHaveKey('rotation_grace_hours')
        ->and($fields['rotation_grace_hours']->type->value)->toBe('integer');
});

test('a rotated signing secret also keeps working during grace', function () {
    $source = DataSource::factory()->create();
    $old = issueSecret($source);
    $new = issueSecret($source->fresh(), graceHours: 24);

    // Both are legitimate during a rotation, and the sender decides which it
    // used, so 8.3 has to try each.
    expect($source->fresh()->signingSecrets())->toBe([$new->signingSecret, $old->signingSecret]);
});

test('outside the grace window only the current signing secret is offered', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $source = DataSource::factory()->create();
    issueSecret($source);
    $new = issueSecret($source->fresh(), graceHours: 1);

    Carbon::setTestNow('2026-09-13 11:00:00');

    expect($source->fresh()->signingSecrets())->toBe([$new->signingSecret]);
});

// -- Revoking ------------------------------------------------------------------

test('a revoked key is rejected', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    app(RevokeSourceSecretAction::class)($source);
    $source->refresh();

    expect($source->verifyKey($credentials->key))->toBeFalse()
        ->and($source->hasSecret())->toBeFalse()
        ->and($source->secretWasRevoked())->toBeTrue()
        ->and($source->signingSecrets())->toBe([]);
});

test('revoking takes the grace key with it', function () {
    $source = DataSource::factory()->create();
    $old = issueSecret($source);
    $new = issueSecret($source->fresh(), graceHours: 24);

    app(RevokeSourceSecretAction::class)($source->fresh());
    $source->refresh();

    // A revoke that left the previous key alive would revoke nothing an
    // attacker was actually using.
    expect($source->verifyKey($new->key))->toBeFalse()
        ->and($source->verifyKey($old->key))->toBeFalse()
        ->and($source->isInGrace())->toBeFalse();
});

test('revoking leaves the source itself switched on', function () {
    $source = DataSource::factory()->create();
    issueSecret($source);

    app(RevokeSourceSecretAction::class)($source);
    $source->refresh();

    // Revoking says "nobody may authenticate as this" and decides nothing else.
    expect($source->is_active)->toBeTrue()
        ->and($source->target_module)->toBe('leads');
});

test('a source that never had a key is not reported as revoked', function () {
    $source = DataSource::factory()->create();

    // Two different situations that need different things said about them.
    expect($source->hasSecret())->toBeFalse()
        ->and($source->secretWasRevoked())->toBeFalse();
});

test('issuing again after a revoke clears the revoked stamp', function () {
    $source = DataSource::factory()->create();
    issueSecret($source);
    app(RevokeSourceSecretAction::class)($source);

    $credentials = issueSecret($source->fresh());
    $source->refresh();

    expect($source->hasSecret())->toBeTrue()
        ->and($source->secretWasRevoked())->toBeFalse()
        ->and($source->verifyKey($credentials->key))->toBeTrue();
});

// -- The secret never escapes ---------------------------------------------------

test('neither credential appears when the model is serialised', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);
    $source->refresh();

    foreach ([json_encode($source->toArray()), json_encode($source), var_export($source->toArray(), true)] as $rendered) {
        expect((string) $rendered)->not->toContain($credentials->key)
            ->and((string) $rendered)->not->toContain($credentials->signingSecret)
            ->and((string) $rendered)->not->toContain($source->secret_hash);
    }
});

test('the credentials object refuses to print itself', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    // Covers a stack trace, a dd(), and anything that casts it to a string.
    expect((string) $credentials)->toBe('[redacted]')
        ->and(print_r($credentials->__debugInfo(), true))->not->toContain($credentials->key)
        ->and(print_r($credentials->__debugInfo(), true))->not->toContain($credentials->signingSecret);
});

test('nothing about a credential reaches the log', function () {
    $lines = [];
    Log::listen(function ($message) use (&$lines) {
        $lines[] = $message->message.' '.json_encode($message->context);
    });

    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);
    app(RevokeSourceSecretAction::class)($source->fresh());

    $written = implode("\n", $lines);

    expect($written)->not->toContain($credentials->key)
        ->and($written)->not->toContain($credentials->signingSecret);
});

test('the audit trail records that a key changed, never anything about its value', function () {
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    $entry = AuditEntry::query()
        ->where('subject_type', $source->getMorphClass())
        ->where('subject_id', $source->id)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->description)->toContain('key was issued');

    $properties = json_encode($entry?->properties);

    expect($properties)->not->toContain($credentials->key)
        ->and($properties)->not->toContain($credentials->signingSecret)
        ->and($properties)->not->toContain((string) $source->fresh()->secret_hash)
        // Not the hint either: the trail is not the place any part of a
        // credential is written down.
        ->and($properties)->not->toContain('secret_hint');
});

test('a rotation and a revoke are each recorded', function () {
    $source = DataSource::factory()->create();
    issueSecret($source);
    issueSecret($source->fresh(), graceHours: 6);
    app(RevokeSourceSecretAction::class)($source->fresh());

    $descriptions = AuditEntry::query()
        ->where('subject_type', $source->getMorphClass())
        ->where('subject_id', $source->id)
        ->orderBy('id')
        ->pluck('description')
        ->all();

    expect($descriptions)->toContain('Data source key was issued')
        ->and($descriptions)->toContain('Data source key was rotated')
        ->and($descriptions)->toContain('Data source key was revoked');
});

// -- The screen ----------------------------------------------------------------

test('the secrets permission is declared in the catalogue', function () {
    expect(PermissionCatalogue::has('integrations.secrets'))->toBeTrue();
});

test('the screen shows a new key once and then puts it away', function () {
    $user = ingestionSecretAdmin();
    $source = DataSource::factory()->create();

    $screen = Livewire::actingAs($user)
        ->test(DataSources::class)
        ->call('issueSecret', $source->id);

    $key = $screen->get('revealedKey');

    expect($key)->not->toBeNull();

    $screen->assertSee($key)
        ->call('dismissSecret')
        ->assertSet('revealedKey', null)
        ->assertSet('revealedSigningSecret', null)
        ->assertDontSee($key);
});

test('a stored key is never rendered on a later visit', function () {
    $user = ingestionSecretAdmin();
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    // A fresh page load has nothing to show: the key cannot be recovered, and
    // the signing secret is deliberately not read back.
    Livewire::actingAs($user)
        ->test(DataSources::class)
        ->assertSet('revealedKey', null)
        ->assertDontSee($credentials->key)
        ->assertDontSee($credentials->signingSecret);
});

test('the list says which key is in use without showing it', function () {
    $user = ingestionSecretAdmin();
    $source = DataSource::factory()->create();
    $credentials = issueSecret($source);

    Livewire::actingAs($user)
        ->test(DataSources::class)
        ->assertSee(substr($credentials->key, -4))
        ->assertDontSee($credentials->key);
});

test('the screen rotates and revokes', function () {
    $user = ingestionSecretAdmin();
    $source = DataSource::factory()->create();
    $first = issueSecret($source);

    $screen = Livewire::actingAs($user)->test(DataSources::class)->call('issueSecret', $source->id);
    $second = $screen->get('revealedKey');

    expect($source->fresh()->verifyKey($second))->toBeTrue()
        ->and($source->fresh()->verifyKey($first->key))->toBeTrue();

    $screen->call('revokeSecret', $source->id)
        ->assertSet('revealedKey', null);

    expect($source->fresh()->hasSecret())->toBeFalse()
        ->and($source->fresh()->verifyKey($second))->toBeFalse();
});

test('somebody without the secrets permission cannot mint or revoke a key', function (string $method) {
    $manager = ingestionUser(['integrations.view', 'integrations.manage']);
    $source = DataSource::factory()->create();
    issueSecret($source);
    $before = $source->fresh()->secret_hash;

    Livewire::actingAs($manager)
        ->test(DataSources::class)
        ->call($method, $source->id)
        ->assertForbidden();

    expect($source->fresh()->secret_hash)->toBe($before);
})->with(['issueSecret', 'revokeSecret']);

test('somebody without the secrets permission is not offered the controls', function () {
    $manager = ingestionUser(['integrations.view', 'integrations.manage']);
    $source = DataSource::factory()->create();

    $screen = Livewire::actingAs($manager)->test(DataSources::class);

    expect($screen->instance()->canManageSecrets())->toBeFalse();

    $screen->assertDontSeeHtml('wire:click="issueSecret('.$source->id.')"');
});
