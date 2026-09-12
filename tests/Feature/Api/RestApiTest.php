<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Livewire\Settings\ApiTokens;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function apiUser(array $permissions = ['contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete', 'accounts.view', 'leads.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * @param  array<int, string>  $abilities
 */
function keyFor(User $user, array $abilities = ['read']): string
{
    return $user->createToken('Test key', $abilities)->plainTextToken;
}

function apiGet(string $key, string $path)
{
    return test()->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/v1'.$path);
}

beforeEach(function () {
    Cache::flush();
});

// -- Getting in ------------------------------------------------------------------

it('refuses a request with no key at all', function () {
    $this->getJson('/api/v1/contacts')->assertUnauthorized();
});

it('refuses a key that has been revoked', function () {
    $user = apiUser();
    $key = keyFor($user);

    $user->tokens()->delete();

    // Deliberately no successful call first: Sanctum's guard memoises the
    // resolved user, and a test makes both requests against one application
    // instance, so an earlier success would still be cached and this would
    // pass for the wrong reason. Every other test here proves a live key works.
    apiGet($key, '/contacts')->assertUnauthorized();
});

it('says who a key belongs to and what it may do', function () {
    $user = apiUser();

    apiGet(keyFor($user, ['read']), '/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.abilities', ['read']);
});

it('is versioned in the path', function () {
    $key = keyFor(apiUser());

    apiGet($key, '/contacts')->assertOk();

    // No unversioned alias: an integration should have to say which version it
    // was written against.
    $this->withHeader('Authorization', 'Bearer '.$key)->getJson('/api/contacts')->assertNotFound();
});

it('will not serve a collection it does not expose', function () {
    apiGet(keyFor(apiUser()), '/invoices')->assertNotFound();
});

// -- Permissions and access level -------------------------------------------------

it('applies the same permissions the browser applies', function () {
    // The key acts as its owner, so somebody who cannot see leads cannot see
    // them through the API either.
    $user = apiUser(['contacts.view']);

    apiGet(keyFor($user), '/contacts')->assertOk();
    apiGet(keyFor($user), '/leads')->assertForbidden();
});

it('respects the owner access level, listing only what its owner may see', function () {
    $mine = apiUser(['contacts.view']);
    $theirs = User::factory()->create();

    Contact::factory()->create(['owner_id' => $mine->id, 'first_name' => 'Mine']);
    Contact::factory()->create(['owner_id' => $theirs->id, 'first_name' => 'Theirs']);

    $response = apiGet(keyFor($mine), '/contacts')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.first_name'))->toBe('Mine');
});

it('treats a record outside the access level as missing rather than forbidden', function () {
    $mine = apiUser(['contacts.view']);
    $theirs = Contact::factory()->create(['owner_id' => User::factory()->create()->id]);

    // 403 would confirm the record exists, which is the thing being withheld.
    apiGet(keyFor($mine), '/contacts/'.$theirs->id)->assertNotFound();
});

// -- Read-only keys ----------------------------------------------------------------

it('will not let a read-only key write', function () {
    $user = apiUser();
    $key = keyFor($user, ['read']);

    $this->withHeader('Authorization', 'Bearer '.$key)
        ->postJson('/api/v1/contacts', ['first_name' => 'New', 'last_name' => 'Person'])
        ->assertForbidden();

    expect(Contact::query()->count())->toBe(0);
});

it('lets a read-and-write key create, change and delete', function () {
    $user = apiUser();
    $key = keyFor($user, ['read', 'write']);

    $created = $this->withHeader('Authorization', 'Bearer '.$key)
        ->postJson('/api/v1/contacts', [
            'first_name' => 'Priya',
            'last_name' => 'Ramanathan',
            'email' => 'priya@example.com',
        ])
        ->assertCreated()
        ->json('data');

    expect($created['first_name'])->toBe('Priya');

    $this->withHeader('Authorization', 'Bearer '.$key)
        ->patchJson('/api/v1/contacts/'.$created['id'], ['job_title' => 'Head of Purchasing'])
        ->assertOk()
        ->assertJsonPath('data.job_title', 'Head of Purchasing')
        // A partial update leaves everything it did not mention alone.
        ->assertJsonPath('data.first_name', 'Priya');

    $this->withHeader('Authorization', 'Bearer '.$key)
        ->deleteJson('/api/v1/contacts/'.$created['id'])
        ->assertNoContent();

    expect(Contact::query()->count())->toBe(0);
});

it('validates a write rather than storing whatever arrives', function () {
    $key = keyFor(apiUser(), ['read', 'write']);

    $this->withHeader('Authorization', 'Bearer '.$key)
        ->postJson('/api/v1/contacts', ['first_name' => '', 'email' => 'not-an-address'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['first_name', 'email']);
});

// -- Rate limiting -------------------------------------------------------------------

it('enforces the rate limit from settings, per key', function () {
    settings()->set('api.rate_limit_per_minute', 30);

    $user = apiUser();
    $first = keyFor($user, ['read']);
    $second = keyFor($user, ['read']);

    for ($i = 0; $i < 30; $i++) {
        apiGet($first, '/contacts')->assertOk();
    }

    apiGet($first, '/contacts')->assertStatus(429);

    // The limit is per key, so the same person's second integration is
    // untouched by the first one's burst.
    apiGet($second, '/contacts')->assertOk();
});

// -- The response shape ----------------------------------------------------------------

it('returns declared fields rather than whatever is on the table', function () {
    $account = Account::factory()->create();
    $user = apiUser();
    $contact = Contact::factory()->create(['owner_id' => $user->id, 'account_id' => $account->id]);

    $fields = array_keys(apiGet(keyFor($user), '/contacts/'.$contact->id)->json('data'));

    expect($fields)->toBe([
        'id', 'first_name', 'last_name', 'job_title', 'email', 'phone', 'mobile',
        'account', 'owner_id', 'created_at', 'updated_at',
    ]);
});

it('pages a collection and says how much there is', function () {
    $user = apiUser();
    Contact::factory()->count(3)->create(['owner_id' => $user->id]);

    $response = apiGet(keyFor($user), '/contacts?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.last_page'))->toBe(2);
});

it('will not let a request ask for an unbounded page', function () {
    $user = apiUser();
    Contact::factory()->count(3)->create(['owner_id' => $user->id]);

    expect(apiGet(keyFor($user), '/contacts?per_page=100000')->json('meta.per_page'))->toBe(100);
});

// -- Managing the keys ------------------------------------------------------------------

it('needs a permission to manage keys', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.api-tokens'))
        ->assertForbidden();

    $this->actingAs(apiUser(['api.tokens']))
        ->get(route('settings.api-tokens'))
        ->assertOk();
});

it('shows a new key once and never again', function () {
    $user = apiUser(['api.tokens']);

    $component = Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('name', 'Website feed')
        ->set('access', 'write')
        ->call('create');

    $plain = $component->get('plainTextToken');

    expect($plain)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(1)
        // Hashed at rest: the stored row is not the key.
        ->and($user->tokens()->first()->token)->not->toBe($plain)
        ->and($user->tokens()->first()->abilities)->toBe(['read', 'write']);

    // And it is gone from the page the moment the administrator moves on.
    $component->call('dismissToken')->assertSet('plainTextToken', null);
});

it('revokes only the signed-in person own keys', function () {
    $mine = apiUser(['api.tokens']);
    $theirs = User::factory()->create();
    $theirToken = $theirs->createToken('Not yours');

    Livewire::actingAs($mine)
        ->test(ApiTokens::class)
        ->call('revoke', $theirToken->accessToken->getKey());

    expect($theirs->tokens()->count())->toBe(1);
});
