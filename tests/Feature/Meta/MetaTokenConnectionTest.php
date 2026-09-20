<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Meta\Actions\ConnectMetaWithTokenAction;
use App\Domain\Meta\Actions\DisconnectMetaAccountAction;
use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\MetaConnectionTester;
use App\Domain\Meta\MetaUrls;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Domain\Settings\SettingsManager;
use App\Livewire\Meta\MetaConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Connecting Meta by pasting a system user token.
 *
 * The OAuth flow needs a public HTTPS address to be redirected back to, which a
 * CRM on a company network does not have. This is the way in for those, and the
 * rules it has to keep are: check the token, read each asset by id rather than
 * by listing, and never lose four good identifiers because the fifth was wrong.
 */
function metaTokenConnector(array $permissions = ['meta.view', 'meta.connect', 'meta.manage', 'meta.sync']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

function metaTokenFake(array $overrides = []): void
{
    // debug_token is called with an app token, so the app credentials have to be
    // stored before any of this works — the same order a real installation does
    // it in: Settings → Meta first, connection second.
    app(SettingsManager::class)->set('meta.app_id', '1772978827247269');
    app(SettingsManager::class)->set('meta.app_secret', 'app-secret-value');

    Http::fake(array_merge([
        // debug_token, through the app token.
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => [
            'is_valid' => true,
            // Zero is what a system user token reports: it does not expire.
            'expires_at' => 0,
            'scopes' => MetaAuthService::SCOPES,
        ]]),
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'app|token']),
        'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [
            ['id' => '55500011122233', 'name' => 'Golden Info Systems Ltd.'],
        ]]),
    ], $overrides));
}

test('a token and its asset ids become a connection', function () {
    metaTokenFake([
        'graph.facebook.com/*/106069340959538?*' => Http::response([
            'id' => '106069340959538',
            'name' => 'Golden Info Systems Ltd.',
            'category' => 'Software company',
            'access_token' => 'page-token-value',
        ]),
        'graph.facebook.com/*/act_23914816791552795?*' => Http::response([
            'account_id' => '23914816791552795',
            'name' => 'GIS Ads',
            'currency' => 'BDT',
            'timezone_name' => 'Asia/Dhaka',
            'account_status' => 1,
        ]),
        'graph.facebook.com/*/1405962320928347?*' => Http::response([
            'id' => '1405962320928347',
            'name' => 'GIS WhatsApp',
        ]),
        'graph.facebook.com/*/1405962320928347/phone_numbers*' => Http::response(['data' => [
            ['id' => '1310844125447923', 'display_phone_number' => '+880 1895-657039', 'verified_name' => 'Golden Info Systems'],
        ]]),
    ]);

    $outcome = app(ConnectMetaWithTokenAction::class)('EAAsystemusertokenvaluehere', [
        'page_id' => '106069340959538',
        'ad_account_id' => '23914816791552795',
        'waba_id' => '1405962320928347',
    ], metaTokenConnector());

    expect($outcome['account']->business_id)->toBe('55500011122233')
        // A permanent token has no expiry, and null is the honest way to say so.
        ->and($outcome['account']->token_expires_at)->toBeNull()
        ->and(MetaPage::query()->where('page_id', '106069340959538')->value('access_token'))->toBe('page-token-value')
        ->and(MetaAdAccount::query()->where('ad_account_id', '23914816791552795')->value('currency'))->toBe('BDT')
        ->and(WhatsAppBusinessAccount::query()->where('waba_id', '1405962320928347')->exists())->toBeTrue()
        // The only number connected becomes the one we send from: a business
        // with one number should never have to find that setting.
        ->and(WhatsAppPhoneNumber::query()->where('phone_number_id', '1310844125447923')->value('is_default'))->toBeTrue();

    expect(collect($outcome['results'])->every(fn (array $result): bool => $result['ok']))->toBeTrue();
});

test('an ad account id pasted with the act_ prefix works as well as without', function () {
    metaTokenFake([
        'graph.facebook.com/*/act_23914816791552795?*' => Http::response([
            'account_id' => '23914816791552795',
            'name' => 'GIS Ads',
            'currency' => 'BDT',
        ]),
    ]);

    // Business Manager shows the id without the prefix and the API requires it.
    // Somebody copying from the screen is not making a mistake.
    app(ConnectMetaWithTokenAction::class)('EAAsystemusertokenvaluehere', [
        'ad_account_id' => 'act_23914816791552795',
    ], metaTokenConnector());

    expect(MetaAdAccount::query()->where('ad_account_id', '23914816791552795')->exists())->toBeTrue();
});

test('one wrong identifier does not throw away the others', function () {
    metaTokenFake([
        'graph.facebook.com/*/1405962320928347?*' => Http::response([
            'id' => '1405962320928347',
            'name' => 'GIS WhatsApp',
        ]),
        'graph.facebook.com/*/1405962320928347/phone_numbers*' => Http::response(['data' => [
            ['id' => '1310844125447923', 'display_phone_number' => '+880 1895-657039'],
        ]]),
        // A digit lost while copying.
        'graph.facebook.com/*/10606934095953?*' => Http::response([
            'error' => ['message' => 'Unsupported get request.', 'code' => 100],
        ], 400),
    ]);

    $outcome = app(ConnectMetaWithTokenAction::class)('EAAsystemusertokenvaluehere', [
        'page_id' => '10606934095953',
        'waba_id' => '1405962320928347',
    ], metaTokenConnector());

    $results = collect($outcome['results'])->keyBy('label');

    expect($results['Facebook Page']['ok'])->toBeFalse()
        // The id is echoed back: the commonest cause is a digit lost in copying,
        // and seeing it next to the refusal is how somebody spots that.
        ->and($results['Facebook Page']['detail'])->toContain('10606934095953')
        ->and($results['WhatsApp business account']['ok'])->toBeTrue()
        ->and(WhatsAppBusinessAccount::query()->count())->toBe(1);
});

test('a token Meta refuses connects nothing', function () {
    Http::fake([
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => false]]),
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'app|token']),
    ]);

    expect(fn () => app(ConnectMetaWithTokenAction::class)('EAAnolongervalidtokenvalue', [], metaTokenConnector()))
        ->toThrow(MetaApiException::class);

    expect(MetaAccount::query()->count())->toBe(0);
});

test('a token missing a permission connects and says which', function () {
    metaTokenFake([
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => [
            'is_valid' => true,
            'expires_at' => 0,
            // Everything except WhatsApp, which is the failure that otherwise
            // shows up as a message that will not send, weeks later.
            'scopes' => array_values(array_diff(MetaAuthService::SCOPES, ['whatsapp_business_messaging'])),
        ]]),
    ]);

    $outcome = app(ConnectMetaWithTokenAction::class)('EAAsystemusertokenvaluehere', [], metaTokenConnector());

    $token = collect($outcome['results'])->firstWhere('label', 'Access token');

    expect($token['ok'])->toBeFalse()
        ->and($token['detail'])->toContain('whatsapp_business_messaging')
        // Connected all the same: three working capabilities are worth having
        // while somebody sorts out the fourth.
        ->and($outcome['account']->exists)->toBeTrue();
});

test('a system user token that cannot name a business still connects', function () {
    metaTokenFake([
        // What a system user actually answers: it administers no businesses.
        'graph.facebook.com/*/me/businesses*' => Http::response([
            'error' => ['message' => 'Unsupported get request.', 'code' => 100],
        ], 400),
        'graph.facebook.com/*/me?*' => Http::response(['id' => '778899001122', 'name' => 'GIS System User']),
    ]);

    $outcome = app(ConnectMetaWithTokenAction::class)('EAAsystemusertokenvaluehere', [], metaTokenConnector());

    expect($outcome['account']->business_id)->toBe('778899001122')
        ->and($outcome['account']->name)->toBe('GIS System User');
});

// -- The screen ----------------------------------------------------------------

test('the token form is behind a permission, like the OAuth one', function () {
    metaTokenFake();

    Livewire::actingAs(metaTokenConnector(['meta.view']))
        ->test(MetaConnection::class)
        ->set('token', 'EAAsystemusertokenvaluehere')
        ->call('connectWithToken')
        ->assertForbidden();

    expect(MetaAccount::query()->count())->toBe(0);
});

test('the screen reports what each identifier reached', function () {
    metaTokenFake([
        'graph.facebook.com/*/1405962320928347?*' => Http::response(['id' => '1405962320928347', 'name' => 'GIS WhatsApp']),
        'graph.facebook.com/*/1405962320928347/phone_numbers*' => Http::response(['data' => [
            ['id' => '1310844125447923', 'display_phone_number' => '+880 1895-657039'],
        ]]),
    ]);

    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->set('token', 'EAAsystemusertokenvaluehere')
        ->set('wabaId', '1405962320928347')
        ->call('connectWithToken')
        ->assertHasNoErrors()
        // Never left sitting in the component's state to be shipped back and
        // forth with every later click on this screen.
        ->assertSet('token', '')
        ->assertSee('WhatsApp business account')
        ->assertSee('+880 1895-657039');
});

test('a token too short to be one is refused before Meta is called', function () {
    Http::fake();

    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->set('token', 'nope')
        ->call('connectWithToken')
        ->assertHasErrors(['token']);

    Http::assertNothingSent();
});

test('the screen shows the webhook address for each channel', function () {
    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertSee('/api/webhooks/meta/whatsapp')
        ->assertSee('/api/webhooks/meta/messenger')
        ->assertSee('/api/webhooks/meta/leadgen');
});

// -- Testing a connection made this way ----------------------------------------

test('the test checks the stored assets rather than what the token can list', function () {
    $account = MetaAccount::factory()->create(['granted_scopes' => MetaAuthService::SCOPES]);

    MetaPage::query()->create([
        'meta_account_id' => $account->id,
        'page_id' => '106069340959538',
        'name' => 'Golden Info Systems Ltd.',
        'access_token' => 'page-token-value',
    ]);

    $waba = WhatsAppBusinessAccount::query()->create([
        'meta_account_id' => $account->id,
        'waba_id' => '1405962320928347',
        'name' => 'GIS WhatsApp',
        'access_token' => 'waba-token',
    ]);

    WhatsAppPhoneNumber::query()->create([
        'whatsapp_business_account_id' => $waba->id,
        'phone_number_id' => '1310844125447923',
        'display_number' => '+880 1895-657039',
        'is_default' => true,
    ]);

    Http::fake([
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => [
            'is_valid' => true, 'expires_at' => 0, 'scopes' => MetaAuthService::SCOPES,
        ]]),
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'app|token']),
        // A system user token answers this with nothing, which is exactly the
        // case that used to report "no pages available" on a working install.
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
        'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => []]),
        'graph.facebook.com/*/106069340959538?*' => Http::response(['id' => '106069340959538']),
        'graph.facebook.com/*/1310844125447923?*' => Http::response(['display_phone_number' => '+880 1895-657039']),
    ]);

    $results = collect(app(MetaConnectionTester::class)->run($account))->keyBy('key');

    expect($results['pages']['passed'])->toBeTrue()
        ->and($results['whatsapp_number']['passed'])->toBeTrue()
        ->and($results['whatsapp_number']['detail'])->toContain('+880 1895-657039');
});

test('a connection with no WhatsApp number says so rather than passing quietly', function () {
    $account = MetaAccount::factory()->create(['granted_scopes' => MetaAuthService::SCOPES]);

    Http::fake([
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => [
            'is_valid' => true, 'expires_at' => 0, 'scopes' => MetaAuthService::SCOPES,
        ]]),
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'app|token']),
        'graph.facebook.com/*' => Http::response(['data' => []]),
    ]);

    $results = collect(app(MetaConnectionTester::class)->run($account))->keyBy('key');

    // The scope can be granted while no number is assigned to the system user,
    // and that fails at the first message rather than here.
    expect($results['whatsapp_number']['passed'])->toBeFalse()
        ->and($results['whatsapp_number']['detail'])->toContain('No WhatsApp number is connected');
});

test('the token form is open when there is nothing connected', function () {
    metaTokenFake();

    // It was behind a button, which hid the only way in for every installation
    // Meta cannot redirect to.
    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertSet('showToken', true)
        ->assertSee('Ad account ID')
        ->assertSee('Ads Manager')
        ->assertSee('WhatsApp business account ID');
});

test('the webhook addresses are built from the configured address, not the current host', function () {
    config(['app.url' => 'https://crm.goldeninfotech.com.bd']);

    // Somebody setting Meta up is usually looking at a development host, and an
    // address printed from *this* request would be one Meta can never call.
    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertSee('https://crm.goldeninfotech.com.bd/api/webhooks/meta/whatsapp')
        ->assertSee('Where replies arrive')
        // The field to subscribe, which decides whether anything is delivered.
        ->assertSee('leadgen');
});

test('an installation Meta cannot reach is told so plainly', function () {
    config(['app.url' => 'http://localhost:8080']);

    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertSee('no reply will arrive here');
});

test('a token per asset is stored against that asset', function () {
    metaTokenFake([
        'graph.facebook.com/*/106069340959538?*' => Http::response([
            'id' => '106069340959538',
            'name' => 'Golden Info Systems Ltd.',
        ]),
        'graph.facebook.com/*/1405962320928347?*' => Http::response([
            'id' => '1405962320928347',
            'name' => 'GIS WhatsApp',
        ]),
        'graph.facebook.com/*/1405962320928347/phone_numbers*' => Http::response(['data' => [
            ['id' => '1310844125447923', 'display_phone_number' => '+880 1895-657039'],
        ]]),
    ]);

    // Meta issues tokens per asset, and a business holding three different ones
    // is the ordinary case rather than the exception.
    app(ConnectMetaWithTokenAction::class)('EAAbusinesssystemusertoken', [
        'page_id' => '106069340959538',
        'page_token' => 'EAApagetokenvaluehere',
        'waba_id' => '1405962320928347',
        'waba_token' => 'EAAwhatsapptokenvalue',
    ], metaTokenConnector());

    expect(MetaPage::query()->where('page_id', '106069340959538')->value('access_token'))
        ->toBe('EAApagetokenvaluehere')
        ->and(WhatsAppBusinessAccount::query()->where('waba_id', '1405962320928347')->value('access_token'))
        ->toBe('EAAwhatsapptokenvalue');
});

test('an asset with no token of its own falls back to the connection', function () {
    metaTokenFake([
        'graph.facebook.com/*/1405962320928347?*' => Http::response(['id' => '1405962320928347', 'name' => 'GIS WhatsApp']),
        'graph.facebook.com/*/1405962320928347/phone_numbers*' => Http::response(['data' => [
            ['id' => '1310844125447923', 'display_phone_number' => '+880 1895-657039'],
        ]]),
    ]);

    // One system user holding every asset is equally ordinary, and it must not
    // mean typing the same token four times.
    app(ConnectMetaWithTokenAction::class)('EAAbusinesssystemusertoken', [
        'waba_id' => '1405962320928347',
    ], metaTokenConnector());

    expect(WhatsAppBusinessAccount::query()->where('waba_id', '1405962320928347')->value('access_token'))
        ->toBe('EAAbusinesssystemusertoken');
});

test('the screen takes a token for each asset and keeps none of them afterwards', function () {
    metaTokenFake([
        'graph.facebook.com/*/act_23914816791552795?*' => Http::response([
            'account_id' => '23914816791552795',
            'name' => 'GIS Ads',
            'currency' => 'BDT',
        ]),
    ]);

    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertSee('Holding three tokens is normal')
        ->set('token', 'EAAbusinesssystemusertoken')
        ->set('adAccountId', '23914816791552795')
        ->set('adsToken', 'EAAadsmonitoringtokenvalue')
        ->call('connectWithToken')
        ->assertHasNoErrors()
        ->assertSet('token', '')
        ->assertSet('adsToken', '');

    expect(MetaAdAccount::query()->where('ad_account_id', '23914816791552795')->exists())->toBeTrue();

    // Read with the ad account's own token, not the business one.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'act_23914816791552795')
        && str_contains((string) $request->header('Authorization')[0], 'EAAadsmonitoringtokenvalue'));
});

test('an asset can still be added once something is connected', function () {
    metaTokenFake([
        'graph.facebook.com/*/act_23914816791552795?*' => Http::response([
            'account_id' => '23914816791552795',
            'name' => 'GIS Ads',
            'currency' => 'BDT',
        ]),
    ]);

    // Connected with one of the three tokens, which is what happens: the form
    // used to disappear the moment anything was connected, leaving nowhere to
    // put the other two.
    MetaAccount::factory()->create(['business_id' => '55500011122233', 'user_token' => 'EAAbusinesssystemusertoken']);

    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        // Shut by default now — there *is* a connection — but reachable.
        ->assertSet('showToken', false)
        ->assertSee('Add an asset, or replace a token')
        ->set('showToken', true)
        ->set('token', 'EAAbusinesssystemusertoken')
        ->set('adAccountId', '23914816791552795')
        ->set('adsToken', 'EAAadsmonitoringtokenvalue')
        ->call('connectWithToken')
        ->assertHasNoErrors();

    expect(MetaAdAccount::query()->where('ad_account_id', '23914816791552795')->exists())->toBeTrue()
        // Still one connection, not a second alongside it.
        ->and(MetaAccount::query()->count())->toBe(1);
});

test('the verify token is shown where it has to be pasted', function () {
    metaTokenFake();
    app(SettingsManager::class)->set('meta.verify_token', 'echoed-back-by-meta-once');

    // Stored as a secret, which makes it write-only on the settings screen —
    // so without this an administrator has no way to read back the value they
    // are being asked to paste into Meta.
    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertSee('Verify token')
        ->assertSee('echoed-back-by-meta-once')
        // One token for all three: Meta asks per product, and three headings
        // would imply three values to keep track of.
        ->assertSee('The same token for all three');
});

test('somebody who may only look is not shown the token', function () {
    metaTokenFake();
    app(SettingsManager::class)->set('meta.verify_token', 'echoed-back-by-meta-once');

    Livewire::actingAs(metaTokenConnector(['meta.view']))
        ->test(MetaConnection::class)
        ->assertDontSee('echoed-back-by-meta-once');
});

test('a screen without a verify token mints one rather than complaining', function () {
    metaTokenFake();

    // This used to tell an administrator to go and invent one. There is no
    // moment at which that is useful: the token is ours to choose and this
    // screen is the only place it is ever read.
    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertDontSee('No webhook verify token is set')
        ->assertSee('Verify token');

    expect(app(MetaConfiguration::class)->verifyToken())->not->toBeNull();
});

// -- The addresses Meta is given ----------------------------------------------

test('the OAuth redirect is https even when the application thinks it is not', function () {
    // The real failure: a site served over https behind a proxy, which reaches
    // PHP as plain HTTP. Meta answers that redirect_uri with "Facebook has
    // detected that this app isn't using a secure connection" and the
    // administrator never reaches the consent screen.
    config(['app.url' => 'http://gdncrm.example.net']);

    expect(MetaUrls::callback())
        ->toBe('https://gdncrm.example.net/settings/meta/callback');
});

test('the webhook addresses are https for the same reason', function () {
    config(['app.url' => 'http://gdncrm.example.net']);

    foreach (MetaChannel::cases() as $channel) {
        expect(MetaUrls::webhook($channel))->toStartWith('https://gdncrm.example.net/');
    }
});

test('an https address is left exactly as it is', function () {
    config(['app.url' => 'https://gdncrm.example.net/']);

    expect(MetaUrls::callback())
        ->toBe('https://gdncrm.example.net/settings/meta/callback');
});

test('a private address is not reachable, however it is spelled', function (string $url) {
    config(['app.url' => $url]);

    expect(MetaUrls::isReachable())->toBeFalse()
        // And it is not a misconfiguration to report: an unpublished
        // installation is not a deployment mistake.
        ->and(MetaUrls::looksMisconfigured())->toBeFalse();
})->with([
    'http://localhost:8080',
    'http://127.0.0.1',
    'http://gdncrm.test',
    'http://192.168.1.40',
]);

test('the screen says when the application is generating insecure links', function () {
    metaTokenFake();
    config(['app.url' => 'http://gdncrm.example.net']);

    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->assertSee('Password')
        ->assertSee('TRUSTED_PROXIES')
        ->assertSee('https://gdncrm.example.net/api/webhooks/meta/whatsapp');
});

test('the addresses and the token can be copied', function () {
    metaTokenFake();
    app(SettingsManager::class)->set('meta.verify_token', 'echoed-back-by-meta-once');

    $html = Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->html();

    // One copy control per address, plus the token: reading a 32-character
    // token off a screen and typing it into Meta is how a verify token gets a
    // character wrong.
    expect(substr_count($html, "copied ? 'Copied' : 'Copy'"))
        ->toBe(count(MetaChannel::cases()) + 1);
});

// -- The setup checklist -------------------------------------------------------

test('an unfinished step says what completes it', function () {
    metaTokenFake();

    // A checklist line that names something missing and not how to supply it is
    // a dead end with a tick box beside it. The WhatsApp one was read as a
    // missing field, fairly: there is no field, because Meta owns the list.
    $stages = collect(Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->instance()
        ->stages())->keyBy('label');

    expect($stages['WhatsApp number']['detail'])->toContain('read from Meta rather than typed')
        ->and($stages['Facebook Pages']['detail'])->toContain('Add the Page ID')
        ->and($stages['Ad accounts']['detail'])->toContain('Add the ad account ID');
});

test('a business account with no numbers is a different problem, and says so', function () {
    metaTokenFake();

    $account = MetaAccount::factory()->create();

    // Connected, but nobody has added a number to it in Business Manager —
    // which is not something this screen can fix.
    WhatsAppBusinessAccount::query()->create([
        'meta_account_id' => $account->id,
        'waba_id' => '1405962320928347',
        'name' => 'GIS WhatsApp',
        'access_token' => 'waba-token',
    ]);

    $stages = collect(Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->instance()
        ->stages())->keyBy('label');

    expect($stages['WhatsApp number']['detail'])->toContain('Add one to it in Business Manager');
});

test('more than one number asks which to send from', function () {
    metaTokenFake();

    $account = MetaAccount::factory()->create();

    $waba = WhatsAppBusinessAccount::query()->create([
        'meta_account_id' => $account->id,
        'waba_id' => '1405962320928347',
        'name' => 'GIS WhatsApp',
        'access_token' => 'waba-token',
    ]);

    foreach (['1310844125447923' => '+880 1895-657039', '1310844125447924' => '+880 1895-657040'] as $id => $display) {
        WhatsAppPhoneNumber::query()->create([
            'whatsapp_business_account_id' => $waba->id,
            'phone_number_id' => $id,
            'display_number' => $display,
            'is_default' => false,
        ]);
    }

    $stages = collect(Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->instance()
        ->stages())->keyBy('label');

    expect($stages['WhatsApp number']['detail'])->toContain('Choose which of the 2 numbers');
});

// -- Which permissions are asked for ------------------------------------------

test('an installation can leave out a permission its app is not approved for', function () {
    metaTokenFake();

    // Meta refuses the whole consent screen with "Invalid Scopes" when an app
    // asks for a permission it has not been reviewed for. Lead Ads is the usual
    // one, and it must not lock a business out of Messenger and WhatsApp too.
    app(SettingsManager::class)->set('meta.scopes', 'pages_messaging, ads_read, whatsapp_business_messaging');

    $url = app(MetaAuthService::class)->authorizeUrl('state', 'https://crm.test/callback');

    expect($url)->toContain('pages_messaging')
        ->and($url)->not->toContain('leads_retrieval');
});

test('a scope this application does not use is dropped rather than sent', function () {
    metaTokenFake();

    // The value goes straight into a URL somebody is sent to.
    app(SettingsManager::class)->set('meta.scopes', 'ads_read, drop_database, <script>');

    expect(app(MetaConfiguration::class)->scopes())->toBe(['ads_read']);
});

test('clearing the setting asks for the default again', function () {
    metaTokenFake();
    app(SettingsManager::class)->set('meta.scopes', '');

    expect(app(MetaConfiguration::class)->scopes())
        ->toBe(MetaConfiguration::DEFAULT_SCOPES);
});

test('a permission nobody asked for is reported as not requested, not as refused', function () {
    metaTokenFake();
    app(SettingsManager::class)->set('meta.scopes', 'pages_messaging, ads_read, whatsapp_business_messaging');

    $account = MetaAccount::factory()->create([
        'granted_scopes' => ['pages_messaging', 'ads_read', 'whatsapp_business_messaging'],
    ]);

    $results = collect(app(MetaConnectionTester::class)->run($account))->keyBy('key');

    // A red line nobody can ever clear teaches people to ignore the panel.
    expect($results['leads']['detail'])->toContain('Not requested by this installation')
        ->and($results['messenger']['passed'])->toBeTrue();
});

// -- Disconnecting -------------------------------------------------------------

test('after disconnecting, nothing in the setup still claims to work', function () {
    metaTokenFake();

    $account = MetaAccount::factory()->create(['user_token' => 'EAAlivetoken']);

    MetaPage::query()->create([
        'meta_account_id' => $account->id,
        'page_id' => '106069340959538',
        'name' => 'Golden Info Systems Ltd.',
        'access_token' => 'page-token',
        'is_subscribed' => true,
    ]);

    MetaAdAccount::query()->create([
        'meta_account_id' => $account->id,
        'ad_account_id' => '23914816791552795',
        'name' => 'GIS Ads',
    ]);

    $waba = WhatsAppBusinessAccount::query()->create([
        'meta_account_id' => $account->id,
        'waba_id' => '1405962320928347',
        'name' => 'GIS WhatsApp',
        'access_token' => 'waba-token',
    ]);

    WhatsAppPhoneNumber::query()->create([
        'whatsapp_business_account_id' => $waba->id,
        'phone_number_id' => '1310844125447923',
        'display_number' => '+880 1895-657039',
        'is_default' => true,
    ]);

    app(DisconnectMetaAccountAction::class)($account);

    $stages = collect(Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->instance()
        ->stages())->keyBy('label');

    // The rows stay — every lead attributed to a form on that page still points
    // at it — but nothing may still read as working.
    expect($stages['Business connected']['done'])->toBeFalse()
        ->and($stages['Facebook Pages']['done'])->toBeFalse()
        ->and($stages['Ad accounts']['done'])->toBeFalse()
        ->and($stages['WhatsApp number']['done'])->toBeFalse()
        ->and($stages['WhatsApp number']['detail'])->toContain('nothing can be sent')
        ->and($stages['Facebook Pages']['detail'])->toContain('none usable');

    // And every token is gone, which is what "disconnect" has to mean.
    expect(MetaPage::query()->value('access_token'))->toBeNull()
        ->and(WhatsAppBusinessAccount::query()->value('access_token'))->toBeNull()
        ->and($account->fresh()->user_token)->toBeNull();
});

// -- A deployment carrying the wrong APP_URL -----------------------------------

test('the address being browsed beats a stale configured one', function () {
    metaTokenFake();

    // What a deployment actually looks like: uploaded with the .env it was
    // developed against, so APP_URL still names a laptop while somebody is
    // reading the screen at the real address.
    config(['app.url' => 'http://localhost:8080']);

    $html = $this->actingAs(metaTokenConnector())
        ->get('https://gdncrm.example.net/settings/meta/connect')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('https://gdncrm.example.net/api/webhooks/meta/whatsapp')
        ->and($html)->not->toContain('localhost:8080/api/webhooks');
});

test('browsing a development copy does not overwrite a real configured address', function () {
    config(['app.url' => 'https://gdncrm.example.net']);

    // The reverse case: the configured address is right and the browsed one is
    // a laptop, which must not be pasted into Meta.
    $this->actingAs(metaTokenConnector())
        ->get('http://localhost:8123/settings/meta/connect')
        ->assertOk()
        ->assertSee('https://gdncrm.example.net/api/webhooks/meta/whatsapp', false);
});

test('outside a request the configured address is all there is', function () {
    config(['app.url' => 'https://gdncrm.example.net']);

    // A queue worker or a console command has no browser at the other end.
    expect(MetaUrls::webhook(MetaChannel::WhatsApp))
        ->toBe('https://gdncrm.example.net/api/webhooks/meta/whatsapp');
});

// -- The verify token ----------------------------------------------------------

test('the verify token is minted the first time somebody needs it', function () {
    metaTokenFake();

    expect(app(MetaConfiguration::class)->verifyToken())->toBeNull();

    $component = Livewire::actingAs(metaTokenConnector())->test(MetaConnection::class);

    $minted = $component->instance()->verifyToken();

    expect($minted)->toHaveLength(32)
        // Stable once made: it is copied into Meta's webhook configuration, so
        // a value that changed on its own would break every subscription made
        // with it.
        ->and(Livewire::actingAs(metaTokenConnector())->test(MetaConnection::class)->instance()->verifyToken())
        ->toBe($minted);
});

// -- What is asked for by default ---------------------------------------------

test('Lead Ads is not requested until somebody asks for it', function () {
    metaTokenFake();

    // Meta refuses the entire consent screen over one permission it has not
    // approved, and Lead Ads needs App Review — so including it by default
    // locks a new installation out of Messenger and WhatsApp as well.
    $scopes = app(MetaConfiguration::class)->scopes();

    expect($scopes)->not->toContain('leads_retrieval')
        ->and($scopes)->toContain('pages_messaging')
        ->and($scopes)->toContain('whatsapp_business_messaging');

    $url = app(MetaAuthService::class)->authorizeUrl('state', 'https://crm.test/callback');

    expect($url)->not->toContain('leads_retrieval');
});

test('adding it back is one setting', function () {
    metaTokenFake();
    app(SettingsManager::class)->set('meta.scopes', implode(',', MetaAuthService::SCOPES));

    expect(app(MetaConfiguration::class)->scopes())->toContain('leads_retrieval');
});

// -- Disconnecting completely --------------------------------------------------

test('disconnecting forgets the application credentials as well', function () {
    metaTokenFake();
    app(MetaConfiguration::class)->ensureVerifyToken();

    $account = MetaAccount::factory()->create(['user_token' => 'EAAlivetoken']);

    app(DisconnectMetaAccountAction::class)($account);

    $configuration = app(MetaConfiguration::class);

    // Somebody disconnecting is finished with the app, and a setup screen still
    // reporting itself half configured is what was reported.
    expect($configuration->appId())->toBeNull()
        ->and($configuration->appSecret())->toBeNull()
        ->and($configuration->verifyToken())->toBeNull()
        ->and($configuration->isConfigured())->toBeFalse();
});

test('after disconnecting, every step of the setup reads as undone', function () {
    metaTokenFake();
    app(MetaConfiguration::class)->ensureVerifyToken();

    $account = MetaAccount::factory()->create(['user_token' => 'EAAlivetoken']);

    app(DisconnectMetaAccountAction::class)($account);

    $stages = collect(Livewire::actingAs(metaTokenConnector(['meta.view']))
        ->test(MetaConnection::class)
        ->instance()
        ->stages())->keyBy('label');

    expect($stages['Meta app credentials']['done'])->toBeFalse()
        ->and($stages['Business connected']['done'])->toBeFalse()
        ->and($stages['Webhooks']['done'])->toBeFalse();
});

// -- Testing the webhook address itself ---------------------------------------

test('a correctly answering address reports itself as correct', function () {
    metaTokenFake();
    // example.com is one of the hosts the suite's resolver stub knows: the
    // SSRF guard resolves before calling, and an unknown host is refused
    // before a request is made.
    config(['app.url' => 'https://example.com']);

    // What the real endpoint does: echo the challenge back as plain text.
    // parse_str turns "hub.challenge" into "hub_challenge", exactly as PHP does
    // for the real request.
    Http::fake(['example.com/api/webhooks/meta/*' => function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response((string) ($query['hub_challenge'] ?? ''), 200);
    }]);

    $component = Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->call('testWebhook', 'whatsapp');

    $result = $component->get('webhookTests')['whatsapp'];

    expect($result['ok'])->toBeTrue()
        // And it says where what is left of the problem must be.
        ->and($result['detail'])->toContain('between Meta and here')
        // This host resolves to a public address, so the request really did
        // leave the server: no caveat belongs on the answer.
        ->and($result['detail'])->not->toContain('never left the machine');
});

test('an address that answers without the challenge is reported as it would be refused', function () {
    metaTokenFake();
    config(['app.url' => 'https://example.com']);

    // A 200 from a holding page, a cache, or somebody else's application.
    Http::fake(['example.com/api/webhooks/meta/*' => Http::response('<html>Coming soon</html>', 200)]);

    $result = Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->call('testWebhook', 'whatsapp')
        ->get('webhookTests')['whatsapp'];

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('did not echo the challenge');
});

test('an address that cannot be reached says so rather than failing silently', function () {
    metaTokenFake();
    config(['app.url' => 'https://example.com']);

    Http::fake(['example.com/*' => fn () => throw new ConnectionException('Connection timed out')]);

    $result = Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->call('testWebhook', 'whatsapp')
        ->get('webhookTests')['whatsapp'];

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('could not be reached');
});

test('an address this server resolves to itself is still tested, and the pass says so', function () {
    metaTokenFake();
    // The shape of a real deployment: the site's own domain resolves, from the
    // site's own server, to an address inside the network. The full SSRF guard
    // refuses that, which would leave the test unusable exactly where the
    // problem is.
    config(['app.url' => 'http://localhost:8080']);

    Http::fake(['localhost:8080/api/webhooks/meta/*' => function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response((string) ($query['hub_challenge'] ?? ''), 200);
    }]);

    $result = Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->call('testWebhook', 'whatsapp')
        ->get('webhookTests')['whatsapp'];

    expect($result['ok'])->toBeTrue()
        // A pass here proves the endpoint answers, and nothing about whether
        // Meta could reach it. Claiming otherwise would send somebody looking
        // at their Meta app when their firewall is the problem.
        ->and($result['detail'])->toContain('never left the machine');
});

test('the test refuses an address that is not a web server at all', function () {
    metaTokenFake();
    // 169.254.169.254 is the cloud metadata service. No site's own domain
    // points there, and a request carrying this installation's verify token
    // certainly should not.
    config(['app.url' => 'https://link-local.example.com']);

    Http::fake();

    $result = Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->call('testWebhook', 'whatsapp')
        ->get('webhookTests')['whatsapp'];

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('link-local');

    Http::assertNothingSent();
});

test('the test refuses an address that does not resolve', function () {
    metaTokenFake();
    config(['app.url' => 'https://nowhere.example.org']);

    Http::fake();

    $result = Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        ->call('testWebhook', 'whatsapp')
        ->get('webhookTests')['whatsapp'];

    expect($result['ok'])->toBeFalse()
        ->and($result['detail'])->toContain('does not resolve');

    Http::assertNothingSent();
});

test('testing an address needs more than being able to look at it', function () {
    metaTokenFake();
    config(['app.url' => 'https://example.com']);
    Http::fake();

    Livewire::actingAs(metaTokenConnector(['meta.view']))
        ->test(MetaConnection::class)
        ->call('testWebhook', 'whatsapp')
        ->assertForbidden();
});

test('the webhook list links to what has arrived on each address', function () {
    metaTokenFake();
    $source = DataSource::factory()->create([
        'provider' => MetaSources::PROVIDER,
        'name' => MetaChannel::WhatsApp->sourceName(),
    ]);

    Livewire::actingAs(metaTokenConnector(['meta.view', 'meta.manage', 'integrations.view']))
        ->test(MetaConnection::class)
        ->assertSee(route('settings.integration-log', ['source' => $source->id]), escape: false);
});

test('looking at the webhook list provisions nothing', function () {
    metaTokenFake();

    Livewire::actingAs(metaTokenConnector(['meta.view', 'meta.manage', 'integrations.view']))
        ->test(MetaConnection::class)
        ->assertOk()
        // MetaSources::for() creates the source it cannot find, which would
        // mean opening a settings screen invented three data sources for an
        // installation that has never received anything.
        ->assertSee(route('settings.integration-log'), escape: false);

    expect(DataSource::query()->where('provider', MetaSources::PROVIDER)->count())->toBe(0);
});

test('the screen says what each stored token is and whether Meta still takes it', function () {
    metaTokenFake();

    $account = MetaAccount::factory()->create([
        'user_token' => 'EAAaccounttoken',
        'token_type' => 'SYSTEM_USER',
        'token_app_id' => '1772978827247269',
        'token_error' => 'Meta no longer accepts this token: the user has not authorized this application.',
        'token_checked_at' => now()->subHours(3),
    ]);

    MetaPage::factory()->for($account, 'account')->create([
        'name' => 'Golden Info Systems Ltd.',
        'access_token' => 'EAApagetoken',
        'token_type' => 'PAGE',
        'token_app_id' => '1772978827247269',
        'token_checked_at' => now()->subHours(3),
    ]);

    Livewire::actingAs(metaTokenConnector())
        ->test(MetaConnection::class)
        // Meta's own vocabulary for the kind of credential: a page token
        // pasted into the WhatsApp box is only visible if this is shown.
        ->assertSee('system user token')
        ->assertSee('page token')
        // Two tokens, one app. Seeing the same id twice is the answer to "why
        // does it show the same app for both", rather than a thing to fix.
        ->assertSee('1772978827247269')
        ->assertSee('no longer accepts this token');
});

test('checking the stored tokens needs more than being able to look at them', function () {
    metaTokenFake();
    MetaAccount::factory()->create(['user_token' => 'EAAaccounttoken']);

    Livewire::actingAs(metaTokenConnector(['meta.view']))
        ->test(MetaConnection::class)
        ->call('checkTokens')
        ->assertForbidden();
});
