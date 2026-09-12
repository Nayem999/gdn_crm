<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Settings\SettingsManager;
use App\Livewire\Settings\SettingsGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function mailSettingsUser(array $permissions = ['settings.view', 'settings.update', 'settings.secrets']): User
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

// -- The per-provider field set ----------------------------------------------

it('asks only for the chosen provider credentials', function () {
    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->assertSet('values.provider', 'log')
        ->assertDontSee('Mailgun sending domain')
        ->assertDontSee('SMTP host')
        ->set('values.provider', 'mailgun')
        ->assertSee('Mailgun sending domain')
        ->assertSee('Mailgun private API key')
        ->assertDontSee('SMTP host');
});

it('also asks for the fallback provider credentials', function () {
    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->set('values.provider', 'mailgun')
        ->set('values.fallback', 'smtp')
        ->assertSee('Mailgun sending domain')
        ->assertSee('SMTP host');
});

it('keeps the credentials of a provider it is not asking about', function () {
    settings()->set('mail.mailgun_domain', 'mg.example.com');
    settings()->set('mail.mailgun_secret', 'key-keepme');

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->set('values.provider', 'smtp')
        ->set('values.smtp_host', 'smtp.example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(settings('mail.provider'))->toBe('smtp')
        ->and(settings('mail.mailgun_domain'))->toBe('mg.example.com')
        ->and(settings('mail.mailgun_secret'))->toBe('key-keepme');
});

it('never puts a stored secret into the component state or the page', function () {
    settings()->set('mail.provider', 'sendgrid');
    settings()->set('mail.sendgrid_key', 'SG.super-secret');

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->assertSet('values.sendgrid_key', null)
        ->assertDontSee('SG.super-secret');
});

// -- Test connection ----------------------------------------------------------

it('reports a successful connection in the provider name', function () {
    Http::fake(['api.sendgrid.com/v3/scopes' => Http::response(['scopes' => ['mail.send']])]);

    settings()->set('mail.provider', 'sendgrid');
    settings()->set('mail.sendgrid_key', 'SG.valid');

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->call('testConnection')
        ->assertSet('testError', null)
        ->assertSet('testMessage', 'SendGrid accepted the credentials.');
});

it('surfaces the provider own error when the credentials are wrong', function () {
    Http::fake(['*' => Http::response(['errors' => [['message' => 'The provided authorization grant is invalid']]], 401)]);

    settings()->set('mail.provider', 'sendgrid');
    settings()->set('mail.sendgrid_key', 'SG.wrong');

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->call('testConnection')
        ->assertSet('testMessage', null)
        ->assertSee('The provided authorization grant is invalid');
});

it('never shows a stored secret back, even when the provider quotes it', function () {
    Http::fake(['*' => Http::response(['message' => 'The key SG.leaked-key is not valid'], 401)]);

    settings()->set('mail.provider', 'sendgrid');
    settings()->set('mail.sendgrid_key', 'SG.leaked-key');

    $component = Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->call('testConnection');

    expect($component->get('testError'))
        ->toContain('[redacted]')
        ->not->toContain('SG.leaked-key');
});

it('says what is missing rather than calling a provider it cannot reach', function () {
    Http::fake();

    settings()->set('mail.provider', 'mailgun');

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->call('testConnection')
        ->assertSet('testError', 'Mailgun needs a sending domain and a private API key.');

    Http::assertNothingSent();
});

it('tests what is typed rather than only what is saved', function () {
    Http::fake(['*' => Http::response(['scopes' => []])]);

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->set('values.provider', 'sendgrid')
        ->set('values.sendgrid_key', 'SG.typed-not-saved')
        ->call('testConnection')
        ->assertSet('testError', null);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer SG.typed-not-saved'));

    // And nothing was written on the way past.
    expect(settings()->isSet('mail.sendgrid_key'))->toBeFalse();
});

it('has nothing to connect to when the provider is the log', function () {
    Http::fake();

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->call('testConnection')
        ->assertSet('testMessage', 'Nothing to connect to — messages are written to the application log.');

    Http::assertNothingSent();
});

// -- Send test email ----------------------------------------------------------

it('sends a test message through the configured provider', function () {
    Http::fake(['api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-test'])]);

    settings()->set('mail.provider', 'postmark');
    settings()->set('mail.postmark_token', 'token-1');
    settings()->set('mail.from_address', 'crm@goldeninfotech.com.bd');
    settings()->set('mail.from_name', 'Golden Infotech');

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->set('testDestination', 'admin@example.com')
        ->call('sendSample')
        ->assertSet('testError', null)
        ->assertSee('A test message has gone to admin@example.com through Postmark.');

    Http::assertSent(function ($request) {
        return $request['To'] === 'admin@example.com'
            && $request['From'] === '"Golden Infotech" <crm@goldeninfotech.com.bd>'
            && str_contains((string) $request['HtmlBody'], 'Postmark');
    });
});

it('defaults the test destination to the person doing the testing', function () {
    $user = mailSettingsUser();

    Livewire::actingAs($user)
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->assertSet('testDestination', $user->email);
});

it('refuses to send a test to something that is not an address', function () {
    Http::fake();

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->set('testDestination', 'not-an-address')
        ->call('sendSample')
        ->assertHasErrors(['testDestination' => 'email']);

    Http::assertNothingSent();
});

it('reports a refused test send instead of throwing', function () {
    Http::fake(['*' => Http::response(['Message' => 'Sender signature not confirmed'], 422)]);

    settings()->set('mail.provider', 'postmark');
    settings()->set('mail.postmark_token', 'token-1');

    Livewire::actingAs(mailSettingsUser())
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->set('testDestination', 'admin@example.com')
        ->call('sendSample')
        ->assertSet('testMessage', null)
        ->assertSee('Sender signature not confirmed');
});

// -- Who may do it ------------------------------------------------------------

it('does not let a read-only viewer test or send', function () {
    Http::fake();

    Livewire::actingAs(mailSettingsUser(['settings.view']))
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->assertDontSee('Test connection')
        ->call('testConnection')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('lets someone without the secrets permission test with the stored ones', function () {
    Http::fake(['*' => Http::response(['scopes' => []])]);

    settings()->set('mail.provider', 'sendgrid');
    settings()->set('mail.sendgrid_key', 'SG.stored');

    Livewire::actingAs(mailSettingsUser(['settings.view', 'settings.update']))
        ->test(SettingsGroup::class, ['group' => 'mail'])
        ->assertDontSee('SendGrid API key')
        ->call('testConnection')
        ->assertSet('testError', null);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer SG.stored'));
});
