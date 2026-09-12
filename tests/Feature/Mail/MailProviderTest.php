<?php

use App\Domain\Mail\Contracts\MailProvider;
use App\Domain\Mail\MailConfiguration;
use App\Domain\Mail\MailProviders;
use App\Domain\Mail\Transports\ManagedTransport;
use App\Domain\Notifications\Drivers\MailDriver;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingField;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A message with everything the API providers have to map.
 */
function outgoing(): Email
{
    return (new Email)
        ->from(new Address('crm@example.com', 'GDN CRM'))
        ->to(new Address('buyer@example.com', 'A Buyer'))
        ->cc('manager@example.com')
        ->bcc('archive@example.com')
        ->replyTo('sales@example.com')
        ->subject('Your quote')
        ->text('Plain words')
        ->html('<p>Rich words</p>');
}

/**
 * @param  array<string, mixed>  $credentials
 */
function transportFor(string $provider, array $credentials = []): TransportInterface
{
    /** @var MailProvider $mailProvider */
    $mailProvider = MailProviders::find($provider);

    return $mailProvider->transport($credentials);
}

function configureProvider(string $provider, array $credentials = [], ?string $fallback = null): void
{
    settings()->set('mail.provider', $provider);
    settings()->set('mail.fallback', $fallback ?? MailProviders::NO_FALLBACK);

    foreach ($credentials as $key => $value) {
        settings()->set('mail.'.$key, $value);
    }
}

// ---------------------------------------------------------------------------
// The registry
// ---------------------------------------------------------------------------

it('offers exactly the providers the application supports', function () {
    expect(array_keys(MailProviders::all()))
        ->toBe(['log', 'smtp', 'mailgun', 'brevo', 'sendgrid', 'ses', 'postmark']);
});

it('declares every provider field in the mail settings group, prefixed with the provider key', function () {
    $declared = array_keys(SettingsRegistry::fields('mail'));

    foreach (MailProviders::all() as $key => $provider) {
        foreach ($provider->fields() as $field) {
            expect($field->key)->toStartWith($key.'_')
                ->and($declared)->toContain($field->key);
        }
    }
});

it('keeps every provider credential out of a dotted key, which Livewire would nest', function () {
    foreach (SettingsRegistry::fields('mail') as $key => $field) {
        expect($key)->not->toContain('.');
    }
});

it('marks every API key, token and password as a secret', function () {
    $shouldBeSecret = ['smtp_password', 'mailgun_secret', 'brevo_key', 'sendgrid_key', 'ses_password', 'postmark_token'];

    foreach ($shouldBeSecret as $key) {
        expect(SettingsRegistry::isSecret('mail.'.$key))->toBeTrue("{$key} must be stored as a secret");
    }
});

it('sends nowhere until a provider is configured, and says so honestly', function () {
    expect(settings('mail.provider'))->toBe('log')
        ->and(app(MailConfiguration::class)->activeProvider()->key())->toBe('log')
        ->and(app(MailConfiguration::class)->isConfigured())->toBeTrue()
        ->and(app(MailConfiguration::class)->unavailableReason())->toBeNull();
});

it('reads an unrecognised stored provider as the log provider rather than throwing', function () {
    settings()->set('mail.provider', 'log');
    Setting::query()
        ->where('group', 'mail')->where('key', 'provider')->update(['value' => 'carrier-pigeon']);
    settings()->flush('mail');

    expect(app(MailConfiguration::class)->activeProvider()->key())->toBe('log');
});

// ---------------------------------------------------------------------------
// The SMTP-shaped providers
// ---------------------------------------------------------------------------

it('builds an SMTP transport from the stored host, port and credentials', function () {
    $transport = transportFor('smtp', [
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 2525,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'postmaster',
        'smtp_password' => 'hunter2',
    ]);

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    /** @var EsmtpTransport $transport */
    /** @var SocketStream $stream */
    $stream = $transport->getStream();

    expect($stream->getHost())->toBe('smtp.example.com')
        ->and($stream->getPort())->toBe(2525)
        ->and($transport->getUsername())->toBe('postmaster')
        ->and($transport->getPassword())->toBe('hunter2')
        ->and($transport->isAutoTls())->toBeTrue();
});

it('only turns the TLS upgrade off when encryption is explicitly none', function () {
    expect(transportFor('smtp', ['smtp_host' => 'localhost', 'smtp_encryption' => 'none']))
        ->isAutoTls()->toBeFalse();

    expect(transportFor('smtp', ['smtp_host' => 'localhost', 'smtp_encryption' => 'ssl']))
        ->isAutoTls()->toBeTrue();
});

it('does not set an SMTP username when none is stored, so an open relay is not asked to authenticate', function () {
    $transport = transportFor('smtp', ['smtp_host' => 'localhost']);

    /** @var EsmtpTransport $transport */
    expect($transport->getUsername())->toBe('');
});

it('derives the SES host from the region', function () {
    $transport = transportFor('ses', [
        'ses_region' => 'eu-west-1',
        'ses_username' => 'AKIAEXAMPLE',
        'ses_password' => 'ses-smtp-password',
    ]);

    /** @var SocketStream $stream */
    $stream = $transport->getStream();

    expect($stream->getHost())->toBe('email-smtp.eu-west-1.amazonaws.com')
        ->and($stream->getPort())->toBe(587);
});

it('asks for what each provider actually needs before calling it configured', function () {
    expect(MailProviders::find('smtp')->missingRequirements([]))->toBe(['a host', 'a port'])
        ->and(MailProviders::find('mailgun')->missingRequirements(['mailgun_domain' => 'mg.example.com']))->toBe(['a private API key'])
        ->and(MailProviders::find('sendgrid')->missingRequirements(['sendgrid_key' => 'SG.x']))->toBe([])
        ->and(MailProviders::find('ses')->missingRequirements([]))->toBe(['a region', 'an SMTP user name', 'an SMTP password'])
        ->and(MailProviders::find('postmark')->missingRequirements([]))->toBe(['a server API token'])
        ->and(MailProviders::find('brevo')->missingRequirements([]))->toBe(['an API key'])
        ->and(MailProviders::find('log')->missingRequirements([]))->toBe([]);
});

// ---------------------------------------------------------------------------
// The API providers
// ---------------------------------------------------------------------------

it('posts a message to Mailgun with the sending domain and the private key', function () {
    Http::fake(['api.mailgun.net/*' => Http::response(['id' => '<20260912.1@mg.example.com>'])]);

    $sent = transportFor('mailgun', ['mailgun_domain' => 'mg.example.com', 'mailgun_secret' => 'key-abc'])
        ->send(outgoing());

    Http::assertSent(function ($request) {
        // No attachment here, so the body is plain form fields rather than
        // multipart parts.
        $fields = $request->data();

        return $request->url() === 'https://api.mailgun.net/v3/mg.example.com/messages'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('api:key-abc'))
            && $fields['to'] === '"A Buyer" <buyer@example.com>'
            && $fields['cc'] === 'manager@example.com'
            && $fields['bcc'] === 'archive@example.com'
            && $fields['h:Reply-To'] === 'sales@example.com'
            && $fields['subject'] === 'Your quote'
            && $fields['html'] === '<p>Rich words</p>'
            && $fields['text'] === 'Plain words';
    });

    expect($sent?->getMessageId())->toBe('20260912.1@mg.example.com');
});

it('posts to the European Mailgun host when the region says so', function () {
    Http::fake(['*' => Http::response(['id' => '<a@b>'])]);

    transportFor('mailgun', [
        'mailgun_domain' => 'mg.example.com',
        'mailgun_secret' => 'key-abc',
        'mailgun_region' => 'eu',
    ])->send(outgoing());

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.eu.mailgun.net/'));
});

it('posts a message to Brevo with the sender, recipients and both bodies', function () {
    Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<brevo-1@smtp>'], 201)]);

    $sent = transportFor('brevo', ['brevo_key' => 'xkeysib-1'])->send(outgoing());

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->hasHeader('api-key', 'xkeysib-1')
            && $request['sender'] === ['email' => 'crm@example.com', 'name' => 'GDN CRM']
            && $request['to'] === [['email' => 'buyer@example.com', 'name' => 'A Buyer']]
            && $request['cc'] === [['email' => 'manager@example.com']]
            && $request['bcc'] === [['email' => 'archive@example.com']]
            && $request['replyTo'] === ['email' => 'sales@example.com']
            && $request['subject'] === 'Your quote'
            && $request['htmlContent'] === '<p>Rich words</p>'
            && $request['textContent'] === 'Plain words';
    });

    expect($sent?->getMessageId())->toBe('brevo-1@smtp');
});

it('posts a message to SendGrid with the plain part before the HTML part', function () {
    Http::fake(['api.sendgrid.com/*' => Http::response('', 202, ['X-Message-Id' => 'sg-1'])]);

    $sent = transportFor('sendgrid', ['sendgrid_key' => 'SG.abc'])->send(outgoing());

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
            && $request->hasHeader('Authorization', 'Bearer SG.abc')
            && $request['personalizations'][0]['to'] === [['email' => 'buyer@example.com', 'name' => 'A Buyer']]
            && $request['personalizations'][0]['cc'] === [['email' => 'manager@example.com']]
            && $request['from'] === ['email' => 'crm@example.com', 'name' => 'GDN CRM']
            && $request['reply_to'] === ['email' => 'sales@example.com']
            && $request['content'][0]['type'] === 'text/plain'
            && $request['content'][1]['type'] === 'text/html';
    });

    expect($sent?->getMessageId())->toBe('sg-1');
});

it('posts a message to Postmark with its message stream', function () {
    Http::fake(['api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-1'])]);

    $sent = transportFor('postmark', ['postmark_token' => 'token-1', 'postmark_stream' => 'broadcast'])
        ->send(outgoing());

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.postmarkapp.com/email'
            && $request->hasHeader('X-Postmark-Server-Token', 'token-1')
            && $request['MessageStream'] === 'broadcast'
            && $request['To'] === '"A Buyer" <buyer@example.com>'
            && $request['HtmlBody'] === '<p>Rich words</p>';
    });

    expect($sent?->getMessageId())->toBe('pm-1');
});

it('defaults Postmark to the transactional stream', function () {
    Http::fake(['*' => Http::response(['MessageID' => 'pm-2'])]);

    transportFor('postmark', ['postmark_token' => 'token-1'])->send(outgoing());

    Http::assertSent(fn ($request) => $request['MessageStream'] === 'outbound');
});

it('carries attachments to the JSON providers as base64 with their file name', function () {
    Http::fake(['*' => Http::response('', 202, ['X-Message-Id' => 'sg-2'])]);

    $email = outgoing()->attach('quote,pdf-bytes', 'quote.pdf', 'application/pdf');

    transportFor('sendgrid', ['sendgrid_key' => 'SG.abc'])->send($email);

    Http::assertSent(function ($request) {
        $attachment = $request['attachments'][0];

        return $attachment['filename'] === 'quote.pdf'
            && $attachment['type'] === 'application/pdf'
            && base64_decode($attachment['content']) === 'quote,pdf-bytes';
    });
});

it('carries attachments to Mailgun as a file part', function () {
    Http::fake(['*' => Http::response(['id' => '<a@b>'])]);

    $email = outgoing()->attach('quote-pdf-bytes', 'quote.pdf', 'application/pdf');

    transportFor('mailgun', ['mailgun_domain' => 'mg.example.com', 'mailgun_secret' => 'key-abc'])->send($email);

    Http::assertSent(function ($request) {
        $part = collect($request->data())->firstWhere('name', 'attachment');

        return $part !== null
            && $part['filename'] === 'quote.pdf'
            && (string) $part['contents'] === 'quote-pdf-bytes';
    });
});

it('surfaces the provider own words when a send is refused', function () {
    Http::fake(['*' => Http::response(['Message' => 'The from address is not a verified sender.'], 422)]);

    expect(fn () => transportFor('postmark', ['postmark_token' => 'bad'])->send(outgoing()))
        ->toThrow(TransportException::class, 'The from address is not a verified sender.');
});

it('collapses a list of provider errors into one message', function () {
    Http::fake(['*' => Http::response(['errors' => [['message' => 'from is required'], ['message' => 'subject is required']]], 400)]);

    expect(fn () => transportFor('sendgrid', ['sendgrid_key' => 'SG.abc'])->send(outgoing()))
        ->toThrow(TransportException::class, 'from is required; subject is required');
});

// ---------------------------------------------------------------------------
// The managed transport
// ---------------------------------------------------------------------------

it('sends through whichever provider the settings name', function () {
    Http::fake(['*' => Http::response(['MessageID' => 'pm-3'])]);

    configureProvider('postmark', ['postmark_token' => 'token-1']);

    app(ManagedTransport::class)->send(outgoing());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'postmarkapp.com'));
});

it('changes transport the moment the setting changes, with no config cache to clear', function () {
    Http::fake([
        'api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-4']),
        'api.brevo.com/*' => Http::response(['messageId' => '<brevo-2@smtp>'], 201),
    ]);

    configureProvider('postmark', ['postmark_token' => 'token-1']);

    // Resolved once, exactly as a long-lived queue worker would hold it.
    $mailer = Mail::mailer('crm');

    $mailer->raw('First', fn ($message) => $message->to('one@example.com')->subject('One'));

    configureProvider('brevo', ['brevo_key' => 'xkeysib-1']);

    $mailer->raw('Second', fn ($message) => $message->to('two@example.com')->subject('Two'));

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'postmarkapp.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'brevo.com'));
});

it('tries the fallback provider when the primary throws', function () {
    Http::fake([
        'api.postmarkapp.com/*' => Http::response(['Message' => 'Service unavailable'], 503),
        'api.brevo.com/*' => Http::response(['messageId' => '<brevo-3@smtp>'], 201),
    ]);
    Log::spy();

    configureProvider('postmark', [
        'postmark_token' => 'token-1',
        'brevo_key' => 'xkeysib-1',
    ], fallback: 'brevo');

    $sent = app(ManagedTransport::class)->send(outgoing());

    expect($sent?->getMessageId())->toBe('brevo-3@smtp');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'postmarkapp.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'brevo.com'));
    Log::shouldHaveReceived('warning')->once();
});

it('does not reach the fallback when the primary succeeds', function () {
    Http::fake([
        'api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-ok']),
        'api.brevo.com/*' => Http::response(['messageId' => '<never@smtp>'], 201),
    ]);

    configureProvider('postmark', [
        'postmark_token' => 'token-1',
        'brevo_key' => 'xkeysib-1',
    ], fallback: 'brevo');

    app(ManagedTransport::class)->send(outgoing());

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'brevo.com'));
});

it('lets the failure through when there is no fallback', function () {
    Http::fake(['*' => Http::response(['Message' => 'Unauthorized'], 401)]);

    configureProvider('postmark', ['postmark_token' => 'wrong']);

    expect(fn () => app(ManagedTransport::class)->send(outgoing()))
        ->toThrow(TransportException::class, 'Unauthorized');
});

it('treats a fallback that is the primary as no fallback at all', function () {
    configureProvider('postmark', ['postmark_token' => 'token-1'], fallback: 'postmark');

    expect(app(MailConfiguration::class)->fallbackProvider())->toBeNull();
});

it('stamps the company from address on a message that did not choose one', function () {
    Http::fake(['*' => Http::response(['MessageID' => 'pm-5'])]);

    configureProvider('postmark', ['postmark_token' => 'token-1']);
    settings()->set('mail.from_address', 'sales@goldeninfotech.com.bd');
    settings()->set('mail.from_name', 'Golden Infotech');

    Mail::mailer('crm')->raw('Body', fn ($message) => $message->to('one@example.com')->subject('One'));

    Http::assertSent(fn ($request) => $request['From'] === '"Golden Infotech" <sales@goldeninfotech.com.bd>');
});

it('leaves a from address the message chose deliberately', function () {
    Http::fake(['*' => Http::response(['MessageID' => 'pm-6'])]);

    configureProvider('postmark', ['postmark_token' => 'token-1']);
    settings()->set('mail.from_address', 'sales@goldeninfotech.com.bd');

    $email = (new Email)
        ->from(new Address('rep@goldeninfotech.com.bd', 'A Rep'))
        ->to('buyer@example.com')
        ->subject('Mine')
        ->text('Body');

    app(ManagedTransport::class)->send($email);

    Http::assertSent(fn ($request) => $request['From'] === '"A Rep" <rep@goldeninfotech.com.bd>');
});

// ---------------------------------------------------------------------------
// What the rest of the application sees
// ---------------------------------------------------------------------------

it('resolves the crm mailer to the managed transport', function () {
    expect(Mail::mailer('crm')->getSymfonyTransport())->toBeInstanceOf(ManagedTransport::class);
});

it('reports the email channel unavailable in the provider own words', function () {
    configureProvider('mailgun');

    $driver = app(MailDriver::class);

    expect($driver->isConfigured())->toBeFalse()
        ->and($driver->unavailableReason())->toBe('Mailgun needs a sending domain and a private API key.');

    settings()->set('mail.mailgun_domain', 'mg.example.com');
    settings()->set('mail.mailgun_secret', 'key-abc');

    expect(app(MailDriver::class)->isConfigured())->toBeTrue()
        ->and(app(MailDriver::class)->unavailableReason())->toBeNull();
});

it('declares every mail setting in the registry, so nothing can be written that is not one', function () {
    expect(SettingsRegistry::find('mail.provider'))->toBeInstanceOf(SettingField::class)
        ->and(settings()->set('mail.smuggled_key', 'value'))->toBeFalse();
});
