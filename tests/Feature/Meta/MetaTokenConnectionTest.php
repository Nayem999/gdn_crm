<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Meta\Actions\ConnectMetaWithTokenAction;
use App\Domain\Meta\Auth\MetaAuthService;
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
