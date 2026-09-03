<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsManager;
use App\Livewire\Settings\SettingsGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  array<int, string>  $permissions
 */
function settingsUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

beforeEach(function () {
    Cache::flush();
    app(SettingsManager::class)->flush();
});

// -- Access -------------------------------------------------------------------

test('a guest cannot reach a settings group', function () {
    $this->get(route('settings.group', 'localisation'))->assertRedirect(route('login'));
});

test('reaching a settings group needs the settings.view permission', function () {
    $this->actingAs(settingsUser())
        ->get(route('settings.group', 'localisation'))
        ->assertForbidden();

    $this->actingAs(settingsUser(['settings.view']))
        ->get(route('settings.group', 'localisation'))
        ->assertOk()
        ->assertSee('Localisation');
});

test('a group the registry does not declare is not routable', function () {
    $this->actingAs(settingsUser(['settings.view']))
        ->get('/settings/made-up-group')
        ->assertNotFound();
});

test('saving needs the settings.update permission', function () {
    Livewire::actingAs(settingsUser(['settings.view']))
        ->test(SettingsGroup::class, ['group' => 'localisation'])
        ->set('values.date_format', 'Y-m-d')
        ->call('save')
        ->assertForbidden();

    expect(settings('localisation.date_format'))->toBe('j M Y');
});

test('someone who cannot update sees no save button', function () {
    Livewire::actingAs(settingsUser(['settings.view']))
        ->test(SettingsGroup::class, ['group' => 'localisation'])
        ->assertDontSee('Save settings')
        ->assertSee('read-only access');
});

// -- Saving -------------------------------------------------------------------

test('a value saves from the screen and reads back', function () {
    Livewire::actingAs(settingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'localisation'])
        ->set('values.date_format', 'Y-m-d')
        ->set('values.week_starts_on', 'sunday')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('settings-saved');

    expect(settings('localisation.date_format'))->toBe('Y-m-d')
        ->and(settings('localisation.week_starts_on'))->toBe('sunday');
});

test('a value outside the field options is rejected', function () {
    Livewire::actingAs(settingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'localisation'])
        ->set('values.date_format', '; DROP TABLE settings')
        ->call('save')
        ->assertHasErrors(['values.date_format']);

    expect(settings('localisation.date_format'))->toBe('j M Y');
});

test('the screen loads the values already stored', function () {
    settings()->set('localisation.date_format', 'Y-m-d');

    Livewire::actingAs(settingsUser(['settings.view']))
        ->test(SettingsGroup::class, ['group' => 'localisation'])
        ->assertSet('values.date_format', 'Y-m-d');
});

// -- Secrets ------------------------------------------------------------------

test('a stored secret is never sent to the browser', function () {
    settings()->set('storage.s3_secret', 'super-secret-value');
    settings()->set('storage.s3_key', 'AKIAEXAMPLE');

    $component = Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage']);

    // Not in the rendered HTML, not in the Livewire snapshot, not in state.
    $component->assertDontSee('super-secret-value')
        ->assertDontSee('AKIAEXAMPLE')
        ->assertSet('values.s3_secret', null)
        ->assertSet('values.s3_key', null)
        // What it shows instead.
        ->assertSee('••••••••')
        ->assertSee('Replace');

    expect($component->html())->not->toContain('super-secret-value')
        ->and(json_encode($component->snapshot))->not->toContain('super-secret-value');
});

test('a full page render never contains a stored secret', function () {
    settings()->set('storage.s3_secret', 'super-secret-value');

    $response = $this->actingAs(settingsUser(['settings.view', 'settings.secrets']))
        ->get(route('settings.group', 'storage'));

    $response->assertOk()->assertDontSee('super-secret-value');
});

test('an unset secret shows an empty field rather than dots', function () {
    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->assertSee('Not set')
        ->assertDontSee('••••••••');
});

test('a secret can be set from the screen and is encrypted at rest', function () {
    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->set('values.s3_secret', 'brand-new-secret')
        ->call('save')
        ->assertHasNoErrors()
        // The form goes back to write-only once it is stored.
        ->assertSet('values.s3_secret', null);

    expect(settings('storage.s3_secret'))->toBe('brand-new-secret')
        ->and(DB::table('settings')->where('key', 's3_secret')->value('value'))
        ->not->toContain('brand-new-secret');
});

test('saving with a secret left blank keeps the stored one', function () {
    settings()->set('storage.s3_secret', 'existing-secret');

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->set('values.s3_bucket', 'uploads')
        ->call('save')
        ->assertHasNoErrors();

    expect(settings('storage.s3_secret'))->toBe('existing-secret')
        ->and(settings('storage.s3_bucket'))->toBe('uploads');
});

test('replace swaps the dots for an input, and cancel puts them back', function () {
    settings()->set('storage.s3_secret', 'existing-secret');

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->call('replace', 's3_secret')
        ->assertSet('replacing', ['s3_secret'])
        ->assertSee('Cancel')
        ->call('cancelReplace', 's3_secret')
        ->assertSet('replacing', [])
        ->assertSet('values.s3_secret', null)
        ->assertSee('••••••••');

    expect(settings('storage.s3_secret'))->toBe('existing-secret');
});

test('a stored secret can be removed outright', function () {
    settings()->set('storage.s3_secret', 'existing-secret');

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->call('clearSecret', 's3_secret')
        ->assertDispatched('settings-saved');

    expect(settings()->isSet('storage.s3_secret'))->toBeFalse()
        ->and(settings('storage.s3_secret'))->toBeNull();
});

// -- Secrets and the settings.secrets permission -------------------------------

test('without the secrets permission the secret fields are not rendered at all', function () {
    settings()->set('storage.s3_secret', 'super-secret-value');

    $component = Livewire::actingAs(settingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'storage']);

    $component->assertSee('Bucket')
        ->assertDontSee('Secret access key')
        ->assertDontSee('super-secret-value')
        ->assertDontSee('••••••••')
        // And they are told why.
        ->assertSee('Read and replace stored credentials');
});

test('without the secrets permission a submitted secret is ignored', function () {
    settings()->set('storage.s3_secret', 'existing-secret');

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->set('values.s3_bucket', 'uploads')
        // A tampered payload naming a secret key.
        ->set('values.s3_secret', 'injected-secret')
        ->call('save');

    expect(settings('storage.s3_secret'))->toBe('existing-secret')
        ->and(settings('storage.s3_bucket'))->toBe('uploads');
});

test('without the secrets permission replace and clear are refused', function () {
    settings()->set('storage.s3_secret', 'existing-secret');

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->call('replace', 's3_secret')
        ->assertForbidden();

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->call('clearSecret', 's3_secret')
        ->assertForbidden();

    expect(settings('storage.s3_secret'))->toBe('existing-secret');
});

test('replace refuses a key that is not a secret', function () {
    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->call('replace', 's3_bucket')
        ->assertHasErrors(['values.s3_bucket']);
});

// -- Audit --------------------------------------------------------------------

test('changing a setting records who changed which key', function () {
    $user = settingsUser(['settings.view', 'settings.update']);

    Livewire::actingAs($user)
        ->test(SettingsGroup::class, ['group' => 'localisation'])
        ->set('values.date_format', 'Y-m-d')
        ->call('save');

    $entry = Activity::query()->where('log_name', 'audit')->latest('id')->firstOrFail();

    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->description)->toContain('Localisation')
        ->and($entry->properties['attributes']['keys'])->toBe(['date_format'])
        ->and($entry->properties['attributes']['values']['date_format'])->toBe('Y-m-d');
});

test('a secret change is audited by key, never by value', function () {
    $user = settingsUser(['settings.view', 'settings.update', 'settings.secrets']);

    Livewire::actingAs($user)
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->set('values.s3_secret', 'super-secret-value')
        ->call('save');

    $entry = Activity::query()->where('log_name', 'audit')->latest('id')->firstOrFail();

    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->properties['attributes']['keys'])->toBe(['s3_secret'])
        ->and($entry->properties['attributes']['values']['s3_secret'])->toBe('[secret changed]')
        ->and(json_encode($entry->properties))->not->toContain('super-secret-value');
});

test('no audit entry is written when nothing actually changed', function () {
    settings()->set('localisation.date_format', 'Y-m-d');

    // Creating the user is itself audited, so count after that, not before.
    $user = settingsUser(['settings.view', 'settings.update']);
    $before = Activity::query()->where('log_name', 'audit')->count();

    Livewire::actingAs($user)
        ->test(SettingsGroup::class, ['group' => 'localisation'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Activity::query()->where('log_name', 'audit')->count())->toBe($before);
});

test('removing a secret is audited too', function () {
    settings()->set('storage.s3_secret', 'existing-secret');

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->call('clearSecret', 's3_secret');

    $entry = Activity::query()->where('log_name', 'audit')->latest('id')->firstOrFail();

    expect($entry->description)->toContain('cleared')
        ->and(json_encode($entry->properties))->not->toContain('existing-secret');
});

// -- Logs ---------------------------------------------------------------------

test('saving a secret writes nothing about it to the application log', function () {
    $written = [];

    Log::listen(function ($message) use (&$written) {
        $written[] = $message->message.' '.json_encode($message->context);
    });

    Livewire::actingAs(settingsUser(['settings.view', 'settings.update', 'settings.secrets']))
        ->test(SettingsGroup::class, ['group' => 'storage'])
        ->set('values.s3_secret', 'super-secret-value')
        ->call('save');

    expect(implode("\n", $written))->not->toContain('super-secret-value');
});

// -- Navigation ---------------------------------------------------------------

test('the settings navigation only lists what the viewer can open', function () {
    $limited = settingsUser(['settings.view']);

    $response = $this->actingAs($limited)->get(route('settings.group', 'localisation'));

    $response->assertOk()
        ->assertSee('Localisation')
        ->assertSee('Storage')
        // No permission for these, so they are not offered.
        ->assertDontSee('Roles &amp; permissions', false)
        ->assertDontSee('Audit log');
});

test('the navigation lists every reachable settings destination', function () {
    $user = settingsUser([
        'settings.view', 'company.view', 'users.view', 'teams.view', 'roles.view', 'audit.view',
    ]);

    $this->actingAs($user)
        ->get(route('settings.group', 'localisation'))
        ->assertOk()
        ->assertSee('Company')
        ->assertSee('Localisation')
        ->assertSee('Storage')
        ->assertSee('Users')
        ->assertSee('Teams')
        ->assertSee('Roles &amp; permissions', false)
        ->assertSee('Audit log');
});

test('the shell marks the current page for screen readers', function () {
    $this->actingAs(settingsUser(['settings.view']))
        ->get(route('settings.group', 'storage'))
        ->assertOk()
        ->assertSee('aria-current="page"', false);
});

test('the active nav item survives a livewire round trip', function () {
    // The shell used to derive "active" from the request URL, which during a
    // Livewire update is POST /livewire/update — so the highlight vanished
    // after the first interaction on the page.
    $component = Livewire::actingAs(settingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'storage']);

    $component->assertSee('aria-current="page"', false);

    $component->set('values.s3_bucket', 'uploads')->call('save');

    // And still on the Storage link specifically. [^<]* keeps the match inside
    // one <a> tag, so it cannot run on into the next link's attributes.
    expect($component->html())
        ->toMatch('#/settings/storage"[^<]*aria-current="page"#')
        ->and($component->html())
        ->not->toMatch('#/settings/localisation"[^<]*aria-current="page"#');
});

test('a policy check on a secret row goes through the secrets permission', function () {
    settings()->set('storage.s3_secret', 'x');
    settings()->set('storage.s3_bucket', 'uploads');

    $plain = settingsUser(['settings.view', 'settings.update']);
    $secret = Setting::query()->where('key', 's3_secret')->firstOrFail();
    $ordinary = Setting::query()->where('key', 's3_bucket')->firstOrFail();

    expect($plain->can('view', $ordinary))->toBeTrue()
        ->and($plain->can('update', $ordinary))->toBeTrue()
        ->and($plain->can('view', $secret))->toBeFalse()
        ->and($plain->can('update', $secret))->toBeFalse();

    $trusted = settingsUser(['settings.view', 'settings.update', 'settings.secrets']);

    expect($trusted->can('view', $secret))->toBeTrue()
        ->and($trusted->can('update', $secret))->toBeTrue();
});
