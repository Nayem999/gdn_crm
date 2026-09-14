<?php

use App\Domain\Access\Actions\SyncPermissionCatalogueAction;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\MetaSettingsTester;
use App\Domain\Settings\SettingsManager;
use App\Domain\Settings\SettingsRegistry;
use App\Livewire\Settings\SettingsGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Task 12.1 — the Meta app's own credentials, and who may touch them.
 */
function metaAdmin(array $permissions = ['settings.view', 'settings.update', 'settings.secrets']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

// -- Configuration -------------------------------------------------------------

test('the credentials come from settings', function () {
    app(SettingsManager::class)->set('meta.app_id', 'from-settings');
    app(SettingsManager::class)->set('meta.app_secret', 'secret-from-settings');

    $configuration = app(MetaConfiguration::class);

    expect($configuration->appId())->toBe('from-settings')
        ->and($configuration->appSecret())->toBe('secret-from-settings')
        ->and($configuration->isConfigured())->toBeTrue();
});

test('the environment is not a source of Meta credentials', function () {
    // Deliberately no fallback. A credential in .env is one in every deploy
    // script, every backup and every config:cache artefact, and it cannot be
    // rotated without a deploy. Setting these should change nothing.
    config([
        'meta.app_id' => 'from-env',
        'meta.app_secret' => 'secret-from-env',
        'meta.verify_token' => 'token-from-env',
        'meta.dataset_id' => 'dataset-from-env',
    ]);

    $configuration = app(MetaConfiguration::class);

    expect($configuration->appId())->toBeNull()
        ->and($configuration->appSecret())->toBeNull()
        ->and($configuration->verifyToken())->toBeNull()
        ->and($configuration->datasetId())->toBeNull()
        ->and($configuration->isConfigured())->toBeFalse();
});

test('nothing is configured until it is stored', function () {
    $configuration = app(MetaConfiguration::class);

    expect($configuration->isConfigured())->toBeFalse()
        ->and($configuration->missing())->toBe(['an app ID', 'an app secret']);
});

test('a blank stored value is nothing rather than a configured emptiness', function () {
    // A cleared row arrives as ''. Treating it as configured would key
    // appsecret_proof with nothing and produce a signature Meta rejects for a
    // reason pointing nowhere near the cause.
    app(SettingsManager::class)->set('meta.app_id', '   ');

    expect(app(MetaConfiguration::class)->appId())->toBeNull();
});

test('the graph version is the one thing with a packaged default', function () {
    // Not a credential: it is what this application was written against, so an
    // installation that has never opened the settings screen can still call
    // Meta. A stored override wins when it is a real version.
    config(['meta.graph_version' => 'v20.0']);

    expect(app(MetaConfiguration::class)->version())->toBe('v20.0');

    app(SettingsManager::class)->set('meta.graph_version', 'v21.0');

    expect(app(MetaConfiguration::class)->version())->toBe('v21.0');
});

test('webhook readiness is asked separately from app readiness', function () {
    app(SettingsManager::class)->set('meta.app_id', 'id');
    app(SettingsManager::class)->set('meta.app_secret', 'secret');

    // A connection can read from Meta perfectly well before any webhook exists;
    // saying "not configured" for that would send somebody looking in the wrong
    // place.
    expect(app(MetaConfiguration::class)->isConfigured())->toBeTrue()
        ->and(app(MetaConfiguration::class)->canVerifyWebhooks())->toBeFalse();
});

// -- Secrecy -------------------------------------------------------------------

test('the app secret and the verify token are stored encrypted', function (string $key) {
    app(SettingsManager::class)->set('meta.'.$key, 'the-real-value');

    $stored = DB::table('settings')->where('group', 'meta')->where('key', $key)->value('value');

    expect($stored)->not->toBe('the-real-value')
        ->and(app(SettingsManager::class)->get('meta.'.$key))->toBe('the-real-value');
})->with([
    'app secret' => ['app_secret'],
    'verify token' => ['verify_token'],
]);

test('the registry declares both of them secret, so nothing can flip them', function () {
    $fields = SettingsRegistry::fields('meta');

    expect($fields['app_secret']->secret)->toBeTrue()
        ->and($fields['verify_token']->secret)->toBeTrue()
        // The app id is public — it is in every OAuth URL — and hiding it would
        // only make it harder to check against the Meta dashboard.
        ->and($fields['app_id']->secret)->toBeFalse();
});

test('a stored secret never reaches the settings screen', function () {
    app(SettingsManager::class)->set('meta.app_secret', 'super-secret-value');

    Livewire::actingAs(metaAdmin())
        ->test(SettingsGroup::class, ['group' => 'meta'])
        ->assertOk()
        ->assertDontSee('super-secret-value');
});

test('somebody without the secrets permission cannot see or write them', function () {
    app(SettingsManager::class)->set('meta.app_secret', 'super-secret-value');

    Livewire::actingAs(metaAdmin(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'meta'])
        ->assertOk()
        ->assertDontSee('super-secret-value')
        ->set('values.app_secret', 'tampered')
        ->call('save');

    expect(app(SettingsManager::class)->get('meta.app_secret'))->toBe('super-secret-value');
});

test('the graph version is validated by the form, not only by the client', function () {
    Livewire::actingAs(metaAdmin())
        ->test(SettingsGroup::class, ['group' => 'meta'])
        ->set('values.graph_version', 'latest')
        ->call('save')
        ->assertHasErrors('values.graph_version');

    Livewire::actingAs(metaAdmin())
        ->test(SettingsGroup::class, ['group' => 'meta'])
        ->set('values.graph_version', 'v21.0')
        ->call('save')
        ->assertHasNoErrors();
});

// -- The tester ----------------------------------------------------------------

test('testing the credentials asks Meta about the app itself', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123', 'name' => 'GDN CRM Integration'])]);

    $message = app(MetaSettingsTester::class)->test([
        'app_id' => '123',
        'app_secret' => 'secret',
    ]);

    expect($message)->toContain('GDN CRM Integration');

    // The app token is the only one that needs nobody to have authorised
    // anything, which is what makes this testable before the wizard is run.
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer 123|secret'));
});

test('a wrong secret fails here rather than three screens into the wizard', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
    ], 401)]);

    expect(fn () => app(MetaSettingsTester::class)->test(['app_id' => '123', 'app_secret' => 'wrong']))
        ->toThrow(RuntimeException::class, 'Invalid OAuth access token.');
});

test('the tester refuses before calling anything when the credentials are absent', function () {
    Http::fake();

    expect(fn () => app(MetaSettingsTester::class)->test([]))
        ->toThrow(RuntimeException::class, 'Add the app ID and the app secret first.');

    Http::assertNothingSent();
});

test('the tester falls back to what is stored, never to the environment', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '555', 'name' => 'Stored App'])]);

    config(['meta.app_id' => 'from-env', 'meta.app_secret' => 'secret-from-env']);

    app(SettingsManager::class)->set('meta.app_id', '555');
    app(SettingsManager::class)->set('meta.app_secret', 'stored-secret');

    expect(app(MetaSettingsTester::class)->test([]))->toContain('Stored App');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer 555|stored-secret'));
});

test('the tester has nothing to send, because an app has no inbox', function () {
    expect(app(MetaSettingsTester::class)->sampleLabel())->toBeNull();
});

// -- Permissions ---------------------------------------------------------------

test('the new permission groups are in the catalogue', function (string $group, int $atLeast) {
    $groups = PermissionCatalogue::groups();

    expect($groups)->toHaveKey($group)
        ->and($groups[$group]['permissions'])->toHaveCount($atLeast);
})->with([
    'meta' => ['meta', 10],
    'social' => ['social', 4],
    'whatsapp' => ['whatsapp', 4],
]);

test('the Super Admin role picks up every new permission', function () {
    $role = app(SyncPermissionCatalogueAction::class)->execute();

    $held = $role->permissions->pluck('name')->all();

    foreach (['meta.view', 'meta.connect', 'meta.conversions.send', 'social.inbox.reply', 'whatsapp.templates.manage'] as $permission) {
        expect($held)->toContain($permission);
    }
});

test('sending outcomes back to Meta is its own permission', function () {
    // It changes what Meta optimises somebody's ad budget towards, which is not
    // the same decision as changing a field mapping.
    expect(PermissionCatalogue::has('meta.conversions.send'))->toBeTrue()
        ->and(PermissionCatalogue::has('meta.manage'))->toBeTrue();

    $user = metaAdmin(['meta.manage']);

    expect($user->can('meta.manage'))->toBeTrue()
        ->and($user->can('meta.conversions.send'))->toBeFalse();
});

// -- Secrets stay out of the repository ----------------------------------------

test('no Meta credential is named in the environment example', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    // These are configured in Settings and stored encrypted. A name here is an
    // invitation to fill it in, and .env.example is what gets committed.
    foreach (['META_APP_ID', 'META_APP_SECRET', 'META_WEBHOOK_VERIFY_TOKEN', 'META_DATASET_ID'] as $name) {
        expect($example)->not->toContain($name);
    }
});

test('no Meta credential is read from the environment', function () {
    $config = (string) file_get_contents(config_path('meta.php'));

    foreach (['META_APP_ID', 'META_APP_SECRET', 'META_WEBHOOK_VERIFY_TOKEN', 'META_DATASET_ID'] as $name) {
        expect($config)->not->toContain($name);
    }

    // What is left is operational and identifies nobody.
    expect($config)->toContain('graph_version')
        ->toContain('usage_ceiling');
});

test('the configuration class reads credentials from settings and nowhere else', function () {
    $source = (string) file_get_contents(app_path('Domain/Meta/MetaConfiguration.php'));

    // One `config()` call, and it is MetaApiVersion's packaged default reached
    // through resolve() — not a credential.
    expect(substr_count($source, 'config('))->toBe(0)
        ->and($source)->toContain('$this->settings->get');
});
test('the graph client is the only thing that names the graph host', function () {
    $offenders = [];

    foreach (['app', 'config'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());

            if (! str_contains($body, 'graph.facebook.com')) {
                continue;
            }

            $allowed = str_ends_with($file->getPathname(), 'MetaApiVersion.php')
                || str_ends_with($file->getPathname(), 'meta.php')
                // 7.6's WhatsApp sender predates this client and is folded into
                // it in 12.10; until then it is the one other caller.
                || str_contains($file->getPathname(), 'Messaging');

            if (! $allowed) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }

    expect($offenders)->toBe([], 'These name the Graph host directly: '.implode(', ', $offenders));
});

test('an unconfigured app cannot be made to call meta', function () {
    Http::fake();

    config(['meta.app_id' => null, 'meta.app_secret' => null]);

    expect(fn () => app(MetaGraphClient::class)->debugToken('anything'))
        ->toThrow(MetaApiException::class);

    Http::assertNothingSent();
});
