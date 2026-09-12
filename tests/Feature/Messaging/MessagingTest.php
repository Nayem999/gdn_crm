<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Messaging\MessagingConfiguration;
use App\Domain\Messaging\MessagingProviders;
use App\Domain\Notifications\ChannelManager;
use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Drivers\SmsDriver;
use App\Domain\Notifications\Drivers\WhatsAppDriver;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Settings\SettingsRegistry;
use App\Livewire\Settings\SettingsGroup;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function messagingUser(array $permissions = ['settings.view', 'settings.update', 'settings.secrets']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function textMessage(?User $user = null, ?string $address = null): NotificationMessage
{
    return new NotificationMessage(
        event: 'test.event',
        channel: NotificationChannel::Sms,
        recipientType: RecipientType::AssignedAgent,
        user: $user,
        address: $address,
        subject: 'Ignored',
        body: 'Your quote is ready.',
    );
}

// -- Which providers serve which channel ----------------------------------------

it('offers each channel only the providers that can serve it', function () {
    expect(array_keys(MessagingProviders::forChannel('sms')))->toBe(['log', 'twilio', 'vonage'])
        ->and(array_keys(MessagingProviders::forChannel('whatsapp')))->toBe(['log', 'twilio', 'cloud_api']);
});

it('declares every provider field in its own settings group, prefixed', function () {
    foreach (['sms', 'whatsapp'] as $channel) {
        $declared = array_keys(SettingsRegistry::fields($channel));

        foreach (MessagingProviders::forChannel($channel) as $key => $provider) {
            foreach ($provider->fields() as $field) {
                expect($field->key)->toStartWith($key.'_')
                    ->and($declared)->toContain($field->key);
            }
        }
    }
});

it('stores every token and secret encrypted', function () {
    foreach (['sms.twilio_token', 'sms.vonage_secret', 'whatsapp.twilio_token', 'whatsapp.cloud_api_token'] as $name) {
        expect(SettingsRegistry::isSecret($name))->toBeTrue("{$name} must be a secret");
    }
});

// -- The providers themselves -----------------------------------------------------

it('sends an SMS through Twilio with the stored account', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'])]);

    settings()->set('sms.provider', 'twilio');
    settings()->set('sms.twilio_sid', 'AC-account');
    settings()->set('sms.twilio_token', 'auth-token');
    settings()->set('sms.twilio_from', '+8801700000000');

    $id = app(MessagingConfiguration::class)->send('sms', '+8801811111111', 'Your quote is ready.');

    expect($id)->toBe('SM123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC-account/Messages.json'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC-account:auth-token'))
            && $request['To'] === '+8801811111111'
            && $request['From'] === '+8801700000000'
            && $request['Body'] === 'Your quote is ready.';
    });
});

it('prefixes both numbers for WhatsApp and neither for SMS', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM124'])]);

    settings()->set('whatsapp.provider', 'twilio');
    settings()->set('whatsapp.twilio_sid', 'AC-account');
    settings()->set('whatsapp.twilio_token', 'auth-token');
    settings()->set('whatsapp.twilio_from', '+8801700000000');

    app(MessagingConfiguration::class)->send('whatsapp', '+8801811111111', 'Hello');

    Http::assertSent(fn ($request) => $request['To'] === 'whatsapp:+8801811111111'
        && $request['From'] === 'whatsapp:+8801700000000');
});

it('treats a Vonage refusal as a failure even though it answers 200', function () {
    // The trap: the HTTP status says the request was understood, not that the
    // message was accepted. A driver that trusts it reports every message as
    // sent, including the ones that were not.
    Http::fake(['rest.nexmo.com/*' => Http::response([
        'messages' => [['status' => '4', 'error-text' => 'Bad credentials']],
    ], 200)]);

    settings()->set('sms.provider', 'vonage');
    settings()->set('sms.vonage_key', 'key');
    settings()->set('sms.vonage_secret', 'secret');
    settings()->set('sms.vonage_from', 'GDN');

    expect(fn () => app(MessagingConfiguration::class)->send('sms', '+8801811111111', 'Hi'))
        ->toThrow(RuntimeException::class, 'Bad credentials');
});

it('returns the Vonage message id when it really was accepted', function () {
    Http::fake(['*' => Http::response(['messages' => [['status' => '0', 'message-id' => 'VG-1']]])]);

    settings()->set('sms.provider', 'vonage');
    settings()->set('sms.vonage_key', 'key');
    settings()->set('sms.vonage_secret', 'secret');
    settings()->set('sms.vonage_from', 'GDN');

    expect(app(MessagingConfiguration::class)->send('sms', '+8801811111111', 'Hi'))->toBe('VG-1');
});

it('sends a WhatsApp message through the Cloud API', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    settings()->set('whatsapp.provider', 'cloud_api');
    settings()->set('whatsapp.cloud_api_phone_number_id', '12345');
    settings()->set('whatsapp.cloud_api_token', 'meta-token');

    expect(app(MessagingConfiguration::class)->send('whatsapp', '+8801811111111', 'Hello'))->toBe('wamid.1');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/12345/messages')
            && $request->hasHeader('Authorization', 'Bearer meta-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['text'] === ['body' => 'Hello'];
    });
});

// -- Credentials come from Settings, not the environment -------------------------

it('takes its credentials from settings, so changing them changes the next message', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'])]);

    settings()->set('sms.provider', 'twilio');
    settings()->set('sms.twilio_sid', 'AC-first');
    settings()->set('sms.twilio_token', 'first-token');
    settings()->set('sms.twilio_from', '+1');

    app(MessagingConfiguration::class)->send('sms', '+2', 'One');

    settings()->set('sms.twilio_sid', 'AC-second');
    settings()->set('sms.twilio_token', 'second-token');

    app(MessagingConfiguration::class)->send('sms', '+2', 'Two');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'AC-first'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'AC-second'));

    // Nothing was read from config; there is no services entry to read.
    expect(config('services.twilio'))->toBeNull();
});

// -- The channel drivers -----------------------------------------------------------

it('says what is missing before anything tries to send', function () {
    settings()->set('sms.provider', 'twilio');

    $driver = app(SmsDriver::class);

    expect($driver->isConfigured())->toBeFalse()
        ->and($driver->unavailableReason())->toBe('Twilio needs an account SID, an auth token and a from number.');

    settings()->set('sms.twilio_sid', 'AC');
    settings()->set('sms.twilio_token', 'tok');
    settings()->set('sms.twilio_from', '+1');

    expect(app(SmsDriver::class)->isConfigured())->toBeTrue()
        ->and(app(SmsDriver::class)->unavailableReason())->toBeNull();
});

it('counts the log provider as configured, because it does what it says', function () {
    expect(app(SmsDriver::class)->isConfigured())->toBeTrue()
        ->and(app(WhatsAppDriver::class)->isConfigured())->toBeTrue();
});

it('sends to a colleague on the number held against their account', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM2'])]);

    settings()->set('sms.provider', 'twilio');
    settings()->set('sms.twilio_sid', 'AC');
    settings()->set('sms.twilio_token', 'tok');
    settings()->set('sms.twilio_from', '+1');

    $colleague = User::factory()->create(['phone' => '+8801999999999']);

    app(SmsDriver::class)->send(textMessage($colleague));

    Http::assertSent(fn ($request) => $request['To'] === '+8801999999999');
});

it('refuses rather than guessing when there is no number', function () {
    expect(fn () => app(SmsDriver::class)->send(textMessage(User::factory()->create())))
        ->toThrow(RuntimeException::class, 'No phone number for this recipient.');
});

it('lets a fake driver stand in for the real one', function () {
    // The contract the notification engine actually depends on: anything that
    // implements ChannelDriver can be swapped in, with no provider behind it.
    $recorder = new class implements ChannelDriver
    {
        /** @var array<int, string> */
        public array $sent = [];

        public function channel(): NotificationChannel
        {
            return NotificationChannel::Sms;
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function unavailableReason(): ?string
        {
            return null;
        }

        public function send(NotificationMessage $message): void
        {
            $this->sent[] = $message->body;
        }
    };

    $manager = app(ChannelManager::class);
    $manager->extend(NotificationChannel::Sms, $recorder);

    $manager->driver(NotificationChannel::Sms)->send(textMessage(null, '+8801811111111'));

    expect($recorder->sent)->toBe(['Your quote is ready.'])
        ->and($manager->driver(NotificationChannel::Sms))->toBe($recorder);
});

// -- The settings screen ------------------------------------------------------------

it('asks only for the chosen provider credentials and can test them', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'AC'])]);

    Livewire::actingAs(messagingUser())
        ->test(SettingsGroup::class, ['group' => 'sms'])
        ->assertDontSee('Vonage API key')
        ->set('values.provider', 'twilio')
        ->assertSee('Twilio Account SID')
        ->assertDontSee('Vonage API key')
        ->set('values.twilio_sid', 'AC-typed')
        ->set('values.twilio_token', 'typed-token')
        ->set('values.twilio_from', '+1')
        ->call('testConnection')
        ->assertSet('testError', null)
        ->assertSet('testMessage', 'Twilio accepted the credentials.');
});

it('sends a test message from the settings screen', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test'])]);

    settings()->set('sms.provider', 'twilio');
    settings()->set('sms.twilio_sid', 'AC');
    settings()->set('sms.twilio_token', 'tok');
    settings()->set('sms.twilio_from', '+1');

    Livewire::actingAs(messagingUser())
        ->test(SettingsGroup::class, ['group' => 'sms'])
        ->set('testDestination', '+8801811111111')
        ->call('sendSample')
        ->assertSet('testError', null)
        ->assertSee('A test message has gone to +8801811111111 through Twilio.');

    Http::assertSent(fn ($request) => $request['To'] === '+8801811111111');
});

it('never shows a stored token back, even when the provider quotes it', function () {
    Http::fake(['*' => Http::response(['message' => 'The token auth-leaked is invalid'], 401)]);

    settings()->set('sms.provider', 'twilio');
    settings()->set('sms.twilio_sid', 'AC');
    settings()->set('sms.twilio_token', 'auth-leaked');
    settings()->set('sms.twilio_from', '+1');

    $component = Livewire::actingAs(messagingUser())
        ->test(SettingsGroup::class, ['group' => 'sms'])
        ->call('testConnection');

    expect($component->get('testError'))
        ->toContain('[redacted]')
        ->not->toContain('auth-leaked');
});
