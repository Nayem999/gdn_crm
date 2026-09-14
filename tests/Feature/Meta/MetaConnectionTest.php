<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Meta\Actions\ConnectMetaAccountAction;
use App\Domain\Meta\Actions\DisconnectMetaAccountAction;
use App\Domain\Meta\Actions\SyncMetaAssetsAction;
use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Auth\MetaToken;
use App\Domain\Meta\Enums\MetaConnectionStatus;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\MetaConnectionTester;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Settings\SettingsManager;
use App\Livewire\Meta\MetaConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Task 12.4 — connecting Meta, and everything that can go wrong doing it.
 */
function metaConnector(array $permissions = ['meta.view', 'meta.connect', 'meta.disconnect', 'meta.manage', 'meta.sync']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

function metaAppConfigured(): void
{
    app(SettingsManager::class)->set('meta.app_id', '1234567890');
    app(SettingsManager::class)->set('meta.app_secret', 'app-secret-value');
}

// -- The authorisation URL -----------------------------------------------------

test('the authorisation URL carries the state and asks for what the integration needs', function () {
    metaAppConfigured();

    $url = app(MetaAuthService::class)->authorizeUrl('state-token', 'https://crm.test/callback');

    expect($url)->toContain('state=state-token')
        ->toContain('client_id=1234567890')
        // Code, not token: the implicit flow puts a credential in a URL
        // fragment, which is a credential in the browser's history.
        ->toContain('response_type=code');

    foreach (['leads_retrieval', 'pages_messaging', 'ads_read', 'whatsapp_business_messaging'] as $scope) {
        expect($url)->toContain($scope);
    }
});

test('an unconfigured app cannot build one', function () {
    expect(fn () => app(MetaAuthService::class)->authorizeUrl('state', 'https://crm.test/callback'))
        ->toThrow(MetaApiException::class);
});

// -- The callback --------------------------------------------------------------

test('a callback whose state does not match connects nothing', function () {
    metaAppConfigured();
    Http::fake();

    $this->actingAs(metaConnector())
        ->withSession([MetaAuthService::STATE_KEY => 'the-real-state'])
        ->get(route('settings.meta.callback', ['code' => 'anything', 'state' => 'a-forged-state']))
        ->assertRedirect(route('settings.meta.connect'));

    // This is the whole security of the endpoint: without it, a link in an
    // email attaches somebody else's Meta business to our CRM.
    expect(MetaAccount::query()->count())->toBe(0);
    expect(session('error'))->toContain('could not be verified');

    Http::assertNothingSent();
});

test('a state is consumed, so a replayed callback cannot work twice', function () {
    metaAppConfigured();
    Http::fake();

    $user = metaConnector();

    $this->actingAs($user)
        ->withSession([MetaAuthService::STATE_KEY => 'one-time'])
        // No code, so the exchange is not attempted — what is being asserted is
        // that the state is gone afterwards.
        ->get(route('settings.meta.callback', ['state' => 'one-time']));

    expect(session()->has(MetaAuthService::STATE_KEY))->toBeFalse();
});

test('a declined consent screen is reported in Meta\'s words, not as a crash', function () {
    metaAppConfigured();
    Http::fake();

    $this->actingAs(metaConnector())
        ->withSession([MetaAuthService::STATE_KEY => 'state'])
        ->get(route('settings.meta.callback', [
            'state' => 'state',
            'error' => 'access_denied',
            'error_description' => 'Permissions error',
        ]))
        ->assertRedirect(route('settings.meta.connect'));

    expect(session('error'))->toContain('Permissions error')
        ->and(MetaAccount::query()->count())->toBe(0);
});

test('somebody without permission cannot start or finish the flow', function () {
    metaAppConfigured();

    $stranger = User::factory()->create();

    $this->actingAs($stranger)->post(route('settings.meta.redirect'))->assertForbidden();
    $this->actingAs($stranger)->get(route('settings.meta.callback', ['state' => 'x']))->assertForbidden();
});

test('starting the flow is a POST, because it writes to the session', function () {
    // A GET that changes state is one a browser can be made to make by an image
    // tag on somebody else's page. The same URI serves the screen on GET, so
    // what is asserted is the route's own methods rather than a rejection.
    $route = Route::getRoutes()->getByName('settings.meta.redirect');

    expect($route->methods())->toContain('POST')
        ->not->toContain('GET');

    // And a GET of that path is the screen, not the redirect.
    expect(Route::getRoutes()->getByName('settings.meta.connect')->methods())->toContain('GET');
});

// -- Exchanging the code -------------------------------------------------------

test('the short-lived token is exchanged and never stored', function () {
    metaAppConfigured();

    Http::fake([
        'graph.facebook.com/*oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'short-lived-token', 'expires_in' => 3600])
            ->push(['access_token' => 'long-lived-token', 'expires_in' => 5184000]),
    ]);

    $token = app(MetaAuthService::class)->exchangeCode('the-code', 'https://crm.test/callback');

    // Meta's first answer lasts an hour. Storing it gives an installation that
    // works all afternoon and is dead by morning.
    expect($token->value)->toBe('long-lived-token')
        ->and($token->expiresAt)->not->toBeNull()
        ->and($token->expiresAt->isAfter(now()->addDays(30)))->toBeTrue();
});

test('an exchange that returns no token is a refusal rather than an empty connection', function () {
    metaAppConfigured();

    Http::fake(['graph.facebook.com/*' => Http::response(['not_a_token' => true])]);

    expect(fn () => app(MetaAuthService::class)->exchangeCode('code', 'https://crm.test/callback'))
        ->toThrow(MetaApiException::class, 'did not return an access token');
});

// -- Connecting ----------------------------------------------------------------

test('connecting stores the business, the token and what Meta actually granted', function () {
    metaAppConfigured();

    Http::fake([
        'graph.facebook.com/*debug_token*' => Http::response(['data' => [
            'is_valid' => true,
            'expires_at' => now()->addDays(60)->timestamp,
            'scopes' => ['leads_retrieval', 'pages_show_list'],
        ]]),
        'graph.facebook.com/*me/businesses*' => Http::response(['data' => [
            ['id' => '77788899', 'name' => 'Golden Infotech'],
        ]]),
    ]);

    $actor = metaConnector();

    $account = app(ConnectMetaAccountAction::class)(new MetaToken('long-token', now()->addDays(60)), $actor);

    expect($account->business_id)->toBe('77788899')
        ->and($account->name)->toBe('Golden Infotech')
        ->and($account->connected_by_id)->toBe($actor->id)
        ->and($account->status())->toBe(MetaConnectionStatus::Connected)
        // A person can decline permissions on the consent screen, and the first
        // sign is otherwise a capability failing weeks later.
        ->and($account->granted_scopes)->toBe(['leads_retrieval', 'pages_show_list'])
        ->and($account->missingScopes(MetaAuthService::SCOPES))->toContain('ads_read');
});

test('the stored token is encrypted at rest', function () {
    $account = MetaAccount::factory()->create(['user_token' => 'the-real-token']);

    $stored = DB::table('meta_accounts')->where('id', $account->id)->value('user_token');

    expect($stored)->not->toBe('the-real-token')
        ->and($account->fresh()->user_token)->toBe('the-real-token');
});

test('a token Meta says is invalid connects nothing', function () {
    metaAppConfigured();

    Http::fake(['graph.facebook.com/*debug_token*' => Http::response(['data' => ['is_valid' => false]])]);

    expect(fn () => app(ConnectMetaAccountAction::class)(new MetaToken('dead-token'), metaConnector()))
        ->toThrow(MetaApiException::class);

    expect(MetaAccount::query()->count())->toBe(0);
});

test('an account administering no business is refused with something to act on', function () {
    metaAppConfigured();

    Http::fake([
        'graph.facebook.com/*debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => []]]),
        'graph.facebook.com/*me/businesses*' => Http::response(['data' => []]),
    ]);

    expect(fn () => app(ConnectMetaAccountAction::class)(new MetaToken('token'), metaConnector()))
        ->toThrow(MetaApiException::class, 'does not administer a business');
});

test('reconnecting the same business keeps its pages rather than making a second row', function () {
    metaAppConfigured();

    $existing = MetaAccount::factory()->create(['business_id' => '77788899']);
    $page = MetaPage::factory()->create(['meta_account_id' => $existing->id]);

    Http::fake([
        'graph.facebook.com/*debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['ads_read']]]),
        'graph.facebook.com/*me/businesses*' => Http::response(['data' => [['id' => '77788899', 'name' => 'Golden Infotech']]]),
    ]);

    app(ConnectMetaAccountAction::class)(new MetaToken('fresh-token'), metaConnector());

    // Its pages, forms and every lead attributed to them stay attached, which
    // is the whole point of reconnecting rather than starting again.
    expect(MetaAccount::query()->count())->toBe(1)
        ->and($page->fresh()->meta_account_id)->toBe($existing->id)
        ->and($existing->fresh()->user_token)->toBe('fresh-token');
});

// -- Reading what the business owns --------------------------------------------

test('syncing reads pages, ad accounts and WhatsApp numbers', function () {
    $account = MetaAccount::factory()->create();

    Http::fake([
        'graph.facebook.com/*me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Golden Pumps', 'category' => 'Retail', 'access_token' => 'page-token'],
        ]]),
        'graph.facebook.com/*me/adaccounts*' => Http::response(['data' => [
            ['account_id' => '222', 'name' => 'Golden Ads', 'currency' => 'BDT', 'account_status' => 1],
        ]]),
        'graph.facebook.com/*owned_whatsapp_business_accounts*' => Http::response(['data' => [
            ['id' => '333', 'name' => 'Golden WhatsApp'],
        ]]),
        'graph.facebook.com/*phone_numbers*' => Http::response(['data' => [
            ['id' => '444', 'display_phone_number' => '+880 1700 000000', 'verified_name' => 'Golden', 'quality_rating' => 'GREEN'],
        ]]),
    ]);

    $counts = app(SyncMetaAssetsAction::class)($account);

    expect($counts)->toBe(['pages' => 1, 'ad_accounts' => 1, 'whatsapp' => 1])
        ->and(MetaPage::query()->where('page_id', '111')->first()->access_token)->toBe('page-token')
        // Per account, not per installation: two accounts in different
        // currencies must never be added together.
        ->and(MetaAdAccount::query()->where('ad_account_id', '222')->first()->currency)->toBe('BDT')
        ->and(WhatsAppPhoneNumber::query()->where('phone_number_id', '444')->exists())->toBeTrue()
        ->and($account->fresh()->last_synced_at)->not->toBeNull();
});

test('a page missing from a later sync keeps its row and its token', function () {
    $account = MetaAccount::factory()->create();
    $page = MetaPage::factory()->create(['meta_account_id' => $account->id, 'page_id' => '111']);

    Http::fake([
        'graph.facebook.com/*me/accounts*' => Http::response(['data' => []]),
        'graph.facebook.com/*' => Http::response(['data' => []]),
    ]);

    app(SyncMetaAssetsAction::class)($account);

    // A transient permission blip must not cost a month of lead attribution.
    expect($page->fresh())->not->toBeNull()
        ->and($page->fresh()->access_token)->not->toBeNull();
});

test('syncing an account with no token refuses rather than calling Meta anonymously', function () {
    Http::fake();

    $account = MetaAccount::factory()->disconnected()->create();

    expect(fn () => app(SyncMetaAssetsAction::class)($account))->toThrow(MetaApiException::class);

    Http::assertNothingSent();
});

// -- Disconnecting -------------------------------------------------------------

test('disconnecting revokes at Meta and clears every stored token', function () {
    $account = MetaAccount::factory()->create();
    $page = MetaPage::factory()->create(['meta_account_id' => $account->id]);
    $waba = WhatsAppBusinessAccount::factory()->create(['meta_account_id' => $account->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    app(DisconnectMetaAccountAction::class)($account);

    // Clearing our copy does not stop the token working — it stops us using it.
    // Only Meta can end it.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'me/permissions'));

    expect($account->fresh()->user_token)->toBeNull()
        ->and($account->fresh()->status())->toBe(MetaConnectionStatus::Disconnected)
        ->and($page->fresh()->access_token)->toBeNull()
        ->and($page->fresh()->is_subscribed)->toBeFalse()
        ->and($waba->fresh()->access_token)->toBeNull();
});

test('disconnecting keeps the rows, because leads point at them', function () {
    $account = MetaAccount::factory()->create();
    MetaPage::factory()->count(2)->create(['meta_account_id' => $account->id]);

    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    app(DisconnectMetaAccountAction::class)($account);

    expect(MetaPage::query()->where('meta_account_id', $account->id)->count())->toBe(2);
});

test('a revoke Meta refuses still disconnects, and says so', function () {
    $account = MetaAccount::factory()->create();

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope', 'code' => 100]], 400)]);

    app(DisconnectMetaAccountAction::class)($account);

    // Meta being unreachable is not a reason to leave somebody connected to
    // something they have said they want nothing more to do with.
    expect($account->fresh()->status())->toBe(MetaConnectionStatus::Disconnected)
        ->and($account->fresh()->user_token)->toBeNull()
        ->and($account->fresh()->last_error)->toContain('revoked');
});

// -- The test tool -------------------------------------------------------------

test('every capability is checked independently, so one failure hides none', function () {
    metaAppConfigured();
    app(SettingsManager::class)->set('meta.verify_token', 'verify-me');

    $account = MetaAccount::factory()->granted(['leads_retrieval'])->create();

    Http::fake([
        'graph.facebook.com/*debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['leads_retrieval']]]),
        'graph.facebook.com/*me/accounts*' => Http::response(['data' => [['id' => '1', 'name' => 'Page']]]),
        'graph.facebook.com/*me/adaccounts*' => Http::response(['data' => []]),
    ]);

    $results = collect(app(MetaConnectionTester::class)->run($account))->keyBy('key');

    expect($results['auth']['passed'])->toBeTrue()
        ->and($results['leads']['passed'])->toBeTrue()
        // Declined on the consent screen — named, so somebody can act on it.
        ->and($results['messenger']['passed'])->toBeFalse()
        ->and($results['messenger']['detail'])->toContain('pages_messaging')
        ->and($results['ads']['passed'])->toBeFalse()
        ->and($results['pages']['passed'])->toBeTrue()
        ->and($results['ad_accounts']['passed'])->toBeFalse()
        ->and($results['webhooks']['passed'])->toBeTrue();
});

test('the tester reports a missing verify token as its own failure', function () {
    metaAppConfigured();

    $account = MetaAccount::factory()->create();

    Http::fake([
        'graph.facebook.com/*debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => []]]),
        'graph.facebook.com/*' => Http::response(['data' => []]),
    ]);

    $results = collect(app(MetaConnectionTester::class)->run($account))->keyBy('key');

    // Meta cannot tell us this: from its side, an unconfigured webhook looks
    // identical to a working one.
    expect($results['webhooks']['passed'])->toBeFalse()
        ->and($results['webhooks']['detail'])->toContain('verify token');
});

test('a connection with no token fails the first check and stops', function () {
    $account = MetaAccount::factory()->disconnected()->create();

    Http::fake();

    $results = app(MetaConnectionTester::class)->run($account);

    expect($results)->toHaveCount(1)
        ->and($results[0]['passed'])->toBeFalse();

    Http::assertNothingSent();
});

// -- The screen ----------------------------------------------------------------

test('the connection screen opens and reports what still has to happen', function () {
    Livewire::actingAs(metaConnector())
        ->test(MetaConnection::class)
        ->assertOk()
        ->assertSee('Connect Meta')
        ->assertSee('Meta app credentials');
});

test('somebody without meta.view cannot open it', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(MetaConnection::class)
        ->assertForbidden();
});

test('the stages report the real state of the connection', function () {
    metaAppConfigured();
    app(SettingsManager::class)->set('meta.verify_token', 'verify-me');

    $account = MetaAccount::factory()->create();
    MetaPage::factory()->create(['meta_account_id' => $account->id]);

    $stages = collect(Livewire::actingAs(metaConnector())
        ->test(MetaConnection::class)
        ->instance()
        ->stages())
        ->keyBy('label');

    expect($stages['Meta app credentials']['done'])->toBeTrue()
        ->and($stages['Business connected']['done'])->toBeTrue()
        ->and($stages['Facebook Pages']['done'])->toBeTrue()
        ->and($stages['Ad accounts']['done'])->toBeFalse()
        ->and($stages['Webhooks']['done'])->toBeTrue();
});

test('an expired connection reads as needing attention, not as disconnected', function () {
    $account = MetaAccount::factory()->expired()->create();

    $stages = collect(Livewire::actingAs(metaConnector())
        ->test(MetaConnection::class)
        ->instance()
        ->stages())
        ->keyBy('label');

    // Still ours, still holding its pages and its history. The fix is to
    // reconnect, not to start again.
    expect($stages['Business connected']['done'])->toBeFalse()
        ->and($stages['Business connected']['detail'])->toContain('Reconnect');
});

test('choosing a WhatsApp number leaves exactly one in use', function () {
    $account = MetaAccount::factory()->create();
    $waba = WhatsAppBusinessAccount::factory()->create(['meta_account_id' => $account->id]);
    $first = WhatsAppPhoneNumber::factory()->default()->create(['whatsapp_business_account_id' => $waba->id]);
    $second = WhatsAppPhoneNumber::factory()->create(['whatsapp_business_account_id' => $waba->id]);

    Livewire::actingAs(metaConnector())
        ->test(MetaConnection::class)
        ->call('useNumber', $second->id);

    // A CRM sending from two numbers produces conversations customers cannot
    // reply to.
    expect($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse()
        ->and(WhatsAppPhoneNumber::query()->where('is_default', true)->count())->toBe(1);
});

test('a number belonging to somebody else\'s connection cannot be chosen', function () {
    // The foreign one first, because its factory creates a connection of its
    // own — and the screen reads the most recent connection, so building them
    // the other way round would make the "foreign" number legitimately ours.
    $otherWaba = WhatsAppBusinessAccount::factory()->create();
    $foreign = WhatsAppPhoneNumber::factory()->create(['whatsapp_business_account_id' => $otherWaba->id]);

    MetaAccount::factory()->create();

    Livewire::actingAs(metaConnector())
        ->test(MetaConnection::class)
        ->call('useNumber', $foreign->id);

    expect($foreign->fresh()->is_default)->toBeFalse();
});

test('somebody who may only view cannot disconnect', function () {
    MetaAccount::factory()->create();

    Livewire::actingAs(metaConnector(['meta.view']))
        ->test(MetaConnection::class)
        ->call('disconnect')
        ->assertForbidden();
});
