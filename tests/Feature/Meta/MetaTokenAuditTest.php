<?php

use App\Domain\Meta\Auth\MetaTokenAudit;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Settings\SettingsManager;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| What Meta thinks of the tokens we hold
|--------------------------------------------------------------------------
|
| A stored token is a claim about the day it was pasted. Meta withdraws them
| without telling anybody — a system user removed from an app, an asset
| unassigned — and until something asks, the application's only way of finding
| out is a message that will not send.
|
*/

/**
 * debug_token's answer for one token, through the app token.
 *
 * @param  array<string, mixed>  $data
 */
function metaAuditFake(array $data): void
{
    app(SettingsManager::class)->set('meta.app_id', '1772978827247269');
    app(SettingsManager::class)->set('meta.app_secret', 'app-secret-value');

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'app|token']),
        'graph.facebook.com/*/debug_token*' => Http::response(['data' => $data]),
    ]);
}

function metaAuditAccount(array $attributes = []): MetaAccount
{
    return MetaAccount::factory()->create(array_merge(['user_token' => 'EAAaccounttoken'], $attributes));
}

test('a token Meta accepts is recorded with what kind it is and who issued it', function () {
    metaAuditFake([
        'app_id' => '1772978827247269',
        'application' => 'GISLConnect',
        'type' => 'SYSTEM_USER',
        'is_valid' => true,
        'expires_at' => 0,
        'scopes' => ['whatsapp_business_messaging'],
    ]);

    $account = metaAuditAccount();

    $results = app(MetaTokenAudit::class)->run($account);

    expect($results[0]['valid'])->toBeTrue()
        ->and($results[0]['detail'])->toBe('Accepted. Does not expire.')
        ->and($account->fresh()->token_type)->toBe('SYSTEM_USER')
        ->and($account->fresh()->token_app_id)->toBe('1772978827247269')
        // Null is the record of a token that worked. The column is a reason,
        // not a flag, so there is nothing to say when there is no reason.
        ->and($account->fresh()->token_error)->toBeNull()
        ->and($account->fresh()->token_checked_at)->not->toBeNull();
});

test('a revoked token is recorded with Meta reason and what to do about it', function () {
    // Exactly what Meta returns for a system user whose authorisation was
    // withdrawn: 200, is_valid false, and the reason inside the envelope.
    metaAuditFake([
        'app_id' => '1772978827247269',
        'type' => 'SYSTEM_USER',
        'is_valid' => false,
        'expires_at' => 0,
        'scopes' => [],
        'error' => [
            'code' => 190,
            'subcode' => 458,
            'message' => 'Error validating access token: The user has not authorized application 1772978827247269.',
        ],
    ]);

    $account = metaAuditAccount();

    $results = app(MetaTokenAudit::class)->run($account);

    expect($results[0]['valid'])->toBeFalse()
        ->and($results[0]['detail'])->toContain('Meta no longer accepts this token')
        // The remedy depends on the kind of token: a system user token is
        // generated in Business settings and pasted, and telling somebody to
        // "reconnect" would send them somewhere that cannot fix it.
        ->and($results[0]['detail'])->toContain('Business settings')
        ->and($account->fresh()->token_error)->toContain('has not authorized');
});

test('a token from another app is named as such, before anything else', function () {
    metaAuditFake([
        'app_id' => '1587537169580285',
        'type' => 'USER',
        // Valid, and still useless here: every call signs with the configured
        // app's secret, so Meta refuses the proof.
        'is_valid' => true,
        'expires_at' => 0,
        'scopes' => ['pages_messaging'],
    ]);

    $results = app(MetaTokenAudit::class)->run(metaAuditAccount());

    expect($results[0]['valid'])->toBeTrue()
        ->and($results[0]['detail'])->toContain('Issued by app 1587537169580285')
        ->and($results[0]['detail'])->toContain('configured with app 1772978827247269');
});

test('a token about to expire says so while there is time to act', function () {
    metaAuditFake([
        'app_id' => '1772978827247269',
        'type' => 'USER',
        'is_valid' => true,
        'expires_at' => now()->addDays(3)->timestamp,
        'scopes' => [],
    ]);

    $results = app(MetaTokenAudit::class)->run(metaAuditAccount());

    expect($results[0]['detail'])->toContain('expires')
        ->and($results[0]['detail'])->toContain('Reconnect with Facebook');
});

test('an asset with no token is not asked about', function () {
    metaAuditFake(['app_id' => '1772978827247269', 'type' => 'PAGE', 'is_valid' => true, 'expires_at' => 0]);

    $account = metaAuditAccount();
    $page = MetaPage::factory()->for($account, 'account')->create(['access_token' => null]);

    $results = app(MetaTokenAudit::class)->run($account);
    $pageResult = collect($results)->firstWhere('label', 'Page: '.$page->name);

    // "No token" and "a token Meta refuses" are different states with
    // different fixes, and asking Meta about nothing would report the second.
    expect($pageResult['detail'])->toBe('No token is stored.')
        ->and($page->fresh()->token_type)->toBeNull();
});

test('every token is asked about independently', function () {
    app(SettingsManager::class)->set('meta.app_id', '1772978827247269');
    app(SettingsManager::class)->set('meta.app_secret', 'app-secret-value');

    $account = metaAuditAccount();
    $page = MetaPage::factory()->for($account, 'account')->create(['access_token' => 'EAApagetoken']);
    $waba = WhatsAppBusinessAccount::factory()->for($account, 'account')->create(['access_token' => 'EAAdeadtoken']);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'app|token']),
        // The real shape of this installation: one dead system user token and
        // one page token that works perfectly. A check that stopped at the
        // first failure would have condemned the page as well.
        'graph.facebook.com/*/debug_token*' => function ($request) {
            $dead = str_contains($request->url(), 'EAAdeadtoken') || str_contains($request->url(), 'EAAaccounttoken');

            return Http::response(['data' => [
                'app_id' => '1772978827247269',
                'type' => $dead ? 'SYSTEM_USER' : 'PAGE',
                'is_valid' => ! $dead,
                'expires_at' => 0,
                'scopes' => [],
                'error' => $dead ? ['code' => 190, 'message' => 'The user has not authorized application 1772978827247269.'] : null,
            ]]);
        },
    ]);

    app(MetaTokenAudit::class)->run($account);

    expect($page->fresh()->token_error)->toBeNull()
        ->and($page->fresh()->token_type)->toBe('PAGE')
        ->and($waba->fresh()->token_error)->not->toBeNull()
        ->and($account->fresh()->token_error)->not->toBeNull();
});

test('the scheduled check reports without failing over a dead token', function () {
    metaAuditFake([
        'app_id' => '1772978827247269',
        'type' => 'SYSTEM_USER',
        'is_valid' => false,
        'expires_at' => 0,
        'error' => ['code' => 190, 'message' => 'The user has not authorized application 1772978827247269.'],
    ]);

    metaAuditAccount();

    // A revoked token is a finding, not a failure of the check. Exiting
    // non-zero would put this in a nightly alert for something the screen
    // already says plainly.
    $this->artisan('meta:check-tokens')
        ->expectsOutputToContain('Meta will not accept')
        ->assertSuccessful();
});

test('the check says so when there is nothing connected', function () {
    $this->artisan('meta:check-tokens')
        ->expectsOutput('No Meta account is connected.')
        ->assertSuccessful();

    Http::assertNothingSent();
});
