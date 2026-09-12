<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Webhooks\MailWebhooks;
use App\Livewire\Mail\EmailDeliveryLog;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function webhookUrl(string $provider, ?string $token = null): string
{
    return '/webhooks/mail/'.$provider.'/'.($token ?? MailWebhooks::token());
}

function loggedMessage(string $provider, string $messageId, string $to = 'buyer@example.com'): EmailMessage
{
    return EmailMessage::factory()->from($provider, $messageId)->to($to)->create();
}

/**
 * @param  array<int, string>  $permissions
 */
function mailLogUser(array $permissions = ['notifications.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

// -- Writing the log ----------------------------------------------------------

it('writes a delivery row for every recipient of a sent message', function () {
    Http::fake(['*' => Http::response(['MessageID' => 'pm-100'])]);

    settings()->set('mail.provider', 'postmark');
    settings()->set('mail.postmark_token', 'token-1');

    Mail::mailer('crm')->raw('Body', function ($message) {
        $message->to('one@example.com')->cc('two@example.com')->subject('Two of you');
    });

    // One row per *to* recipient: a cc is on the same message but is not who
    // the message is about.
    expect(EmailMessage::query()->count())->toBe(1);

    $row = EmailMessage::query()->firstOrFail();

    expect($row->provider)->toBe('postmark')
        ->and($row->message_id)->toBe('pm-100')
        ->and($row->to_email)->toBe('one@example.com')
        ->and($row->subject)->toBe('Two of you')
        ->and($row->status)->toBe(EmailStatus::Sent);
});

// -- Each provider's payload --------------------------------------------------

it('marks a message delivered from a Mailgun webhook', function () {
    $message = loggedMessage('mailgun', 'mg-1');

    $this->postJson(webhookUrl('mailgun'), [
        'event-data' => [
            'event' => 'delivered',
            'timestamp' => now()->timestamp,
            'recipient' => 'buyer@example.com',
            'message' => ['headers' => ['message-id' => '<mg-1>']],
        ],
    ])->assertOk();

    expect($message->refresh()->status)->toBe(EmailStatus::Delivered)
        ->and($message->delivered_at)->not->toBeNull();
});

it('tells a permanent Mailgun failure from a temporary one', function () {
    $hard = loggedMessage('mailgun', 'mg-hard', 'gone@example.com');
    $soft = loggedMessage('mailgun', 'mg-soft', 'full@example.com');

    foreach ([['mg-hard', 'permanent', 'gone@example.com'], ['mg-soft', 'temporary', 'full@example.com']] as [$id, $severity, $to]) {
        $this->postJson(webhookUrl('mailgun'), [
            'event-data' => [
                'event' => 'failed',
                'severity' => $severity,
                'timestamp' => now()->timestamp,
                'recipient' => $to,
                'message' => ['headers' => ['message-id' => '<'.$id.'>']],
                'delivery-status' => ['message' => 'No such user'],
            ],
        ])->assertOk();
    }

    expect($hard->refresh()->status)->toBe(EmailStatus::Bounced)
        ->and($hard->reason)->toBe('No such user')
        ->and($soft->refresh()->status)->toBe(EmailStatus::Failed);
});

it('verifies a Mailgun signature when a signing key is stored', function () {
    settings()->set('mail.mailgun_webhook_key', 'signing-key');

    $message = loggedMessage('mailgun', 'mg-2');

    $payload = fn (string $signature): array => [
        'signature' => ['timestamp' => '1700000000', 'token' => 'abc', 'signature' => $signature],
        'event-data' => [
            'event' => 'delivered',
            'timestamp' => now()->timestamp,
            'recipient' => 'buyer@example.com',
            'message' => ['headers' => ['message-id' => '<mg-2>']],
        ],
    ];

    $this->postJson(webhookUrl('mailgun'), $payload('wrong'))->assertForbidden();

    expect($message->refresh()->status)->toBe(EmailStatus::Sent);

    $this->postJson(webhookUrl('mailgun'), $payload(hash_hmac('sha256', '1700000000abc', 'signing-key')))
        ->assertOk();

    expect($message->refresh()->status)->toBe(EmailStatus::Delivered);
});

it('applies each Postmark record type to the right message', function () {
    $opened = loggedMessage('postmark', 'pm-open', 'open@example.com');
    $clicked = loggedMessage('postmark', 'pm-click', 'click@example.com');
    $hard = loggedMessage('postmark', 'pm-hard', 'hard@example.com');
    $soft = loggedMessage('postmark', 'pm-soft', 'soft@example.com');
    $spam = loggedMessage('postmark', 'pm-spam', 'spam@example.com');

    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Open', 'MessageID' => 'pm-open', 'Recipient' => 'open@example.com', 'ReceivedAt' => now()->toIso8601String()])->assertOk();
    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Click', 'MessageID' => 'pm-click', 'Recipient' => 'click@example.com', 'ReceivedAt' => now()->toIso8601String(), 'OriginalLink' => 'https://example.com/quote'])->assertOk();
    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'MessageID' => 'pm-hard', 'Email' => 'hard@example.com', 'BouncedAt' => now()->toIso8601String(), 'Description' => 'Mailbox does not exist'])->assertOk();
    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'MessageID' => 'pm-soft', 'Email' => 'soft@example.com', 'BouncedAt' => now()->toIso8601String()])->assertOk();
    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'SpamComplaint', 'MessageID' => 'pm-spam', 'Email' => 'spam@example.com', 'BouncedAt' => now()->toIso8601String()])->assertOk();

    expect($opened->refresh()->status)->toBe(EmailStatus::Opened)
        ->and($opened->open_count)->toBe(1)
        ->and($clicked->refresh()->status)->toBe(EmailStatus::Clicked)
        ->and($clicked->click_count)->toBe(1)
        ->and($clicked->events->first()->url)->toBe('https://example.com/quote')
        ->and($hard->refresh()->status)->toBe(EmailStatus::Bounced)
        ->and($hard->reason)->toBe('Mailbox does not exist')
        ->and($soft->refresh()->status)->toBe(EmailStatus::Failed)
        ->and($spam->refresh()->status)->toBe(EmailStatus::Complained);
});

it('strips the routing suffix SendGrid appends to the message id', function () {
    $message = loggedMessage('sendgrid', 'sg-abc');

    $this->postJson(webhookUrl('sendgrid'), [
        ['event' => 'delivered', 'email' => 'buyer@example.com', 'timestamp' => now()->timestamp, 'sg_message_id' => 'sg-abc.filterdrecv-1234'],
        ['event' => 'open', 'email' => 'buyer@example.com', 'timestamp' => now()->addMinute()->timestamp, 'sg_message_id' => 'sg-abc.filterdrecv-1234'],
    ])->assertOk();

    expect($message->refresh()->status)->toBe(EmailStatus::Opened)
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->events)->toHaveCount(2);
});

it('reads a Brevo bounce', function () {
    $message = loggedMessage('brevo', 'brevo-1');

    $this->postJson(webhookUrl('brevo'), [
        'event' => 'hard_bounce',
        'message-id' => '<brevo-1>',
        'email' => 'buyer@example.com',
        'ts_event' => now()->timestamp,
        'reason' => 'unknown user',
    ])->assertOk();

    expect($message->refresh()->status)->toBe(EmailStatus::Bounced)
        ->and($message->reason)->toBe('unknown user');
});

it('reads an SES delivery out of its SNS envelope, matching on our own message id', function () {
    $message = loggedMessage('ses', 'ses-header-id');

    $this->postJson(webhookUrl('ses'), [
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => [
                'messageId' => 'the-ses-id-we-never-saw',
                'commonHeaders' => ['messageId' => '<ses-header-id>'],
                'timestamp' => now()->toIso8601String(),
            ],
            'delivery' => ['timestamp' => now()->toIso8601String(), 'recipients' => ['buyer@example.com']],
        ]),
    ])->assertOk();

    expect($message->refresh()->status)->toBe(EmailStatus::Delivered);
});

// -- The invariants -----------------------------------------------------------

it('ignores a webhook the provider sends twice', function () {
    $message = loggedMessage('postmark', 'pm-dupe');

    $payload = ['RecordType' => 'Open', 'MessageID' => 'pm-dupe', 'Recipient' => 'buyer@example.com', 'ReceivedAt' => now()->toIso8601String()];

    $this->postJson(webhookUrl('postmark'), $payload)->assertOk()->assertJson(['applied' => 1]);
    $this->postJson(webhookUrl('postmark'), $payload)->assertOk()->assertJson(['applied' => 0]);

    expect($message->refresh()->open_count)->toBe(1)
        ->and($message->events)->toHaveCount(1);
});

it('does not walk a message backwards when webhooks arrive out of order', function () {
    $message = loggedMessage('postmark', 'pm-order');

    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Open', 'MessageID' => 'pm-order', 'Recipient' => 'buyer@example.com', 'ReceivedAt' => now()->toIso8601String()])->assertOk();
    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Delivery', 'MessageID' => 'pm-order', 'Recipient' => 'buyer@example.com', 'DeliveredAt' => now()->subMinute()->toIso8601String()])->assertOk();

    expect($message->refresh()->status)->toBe(EmailStatus::Opened)
        ->and($message->delivered_at)->not->toBeNull();
});

it('lets a complaint win over a click', function () {
    $message = loggedMessage('postmark', 'pm-spam-later');

    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Click', 'MessageID' => 'pm-spam-later', 'Recipient' => 'buyer@example.com', 'ReceivedAt' => now()->toIso8601String(), 'OriginalLink' => 'https://example.com'])->assertOk();
    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'SpamComplaint', 'MessageID' => 'pm-spam-later', 'Email' => 'buyer@example.com', 'BouncedAt' => now()->addMinute()->toIso8601String()])->assertOk();

    expect($message->refresh()->status)->toBe(EmailStatus::Complained);
});

it('updates only the recipient the provider named', function () {
    $one = loggedMessage('postmark', 'pm-shared', 'one@example.com');
    $two = loggedMessage('postmark', 'pm-shared', 'two@example.com');

    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'MessageID' => 'pm-shared', 'Email' => 'two@example.com', 'BouncedAt' => now()->toIso8601String()])->assertOk();

    expect($one->refresh()->status)->toBe(EmailStatus::Sent)
        ->and($two->refresh()->status)->toBe(EmailStatus::Bounced);
});

it('applies an event that names nobody to every recipient of the message', function () {
    $one = loggedMessage('postmark', 'pm-all', 'one@example.com');
    $two = loggedMessage('postmark', 'pm-all', 'two@example.com');

    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Delivery', 'MessageID' => 'pm-all', 'DeliveredAt' => now()->toIso8601String()])
        ->assertOk()
        ->assertJson(['applied' => 2]);

    expect($one->refresh()->status)->toBe(EmailStatus::Delivered)
        ->and($two->refresh()->status)->toBe(EmailStatus::Delivered);
});

it('drops an event for a message it never sent', function () {
    $this->postJson(webhookUrl('postmark'), ['RecordType' => 'Delivery', 'MessageID' => 'never-sent', 'DeliveredAt' => now()->toIso8601String()])
        ->assertOk()
        ->assertJson(['applied' => 0]);

    expect(EmailMessage::query()->count())->toBe(0);
});

// -- Getting in ---------------------------------------------------------------

it('refuses a webhook with the wrong token', function () {
    loggedMessage('postmark', 'pm-token');

    $this->postJson(webhookUrl('postmark', 'not-the-token'), ['RecordType' => 'Delivery', 'MessageID' => 'pm-token'])
        ->assertNotFound();
});

it('refuses a provider that reports nothing', function () {
    $this->postJson(webhookUrl('smtp'), ['anything' => true])->assertNotFound();
});

it('does not need a CSRF token, and nothing else gained that exemption', function () {
    $excluded = new ReflectionMethod(ValidateCsrfToken::class, 'getExcludedPaths');
    $excluded->setAccessible(true);

    expect($excluded->invoke(app(ValidateCsrfToken::class)))
        ->toBe(['f/*', 'webhooks/*']);
});

// -- The screen ---------------------------------------------------------------

it('needs the notification permission to read the delivery log', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.mail-log'))
        ->assertForbidden();

    $this->actingAs(mailLogUser())
        ->get(route('settings.mail-log'))
        ->assertOk();
});

it('lists what was sent and shows the address the provider should report to', function () {
    settings()->set('mail.provider', 'postmark');
    loggedMessage('postmark', 'pm-listed', 'listed@example.com');

    Livewire::actingAs(mailLogUser())
        ->test(EmailDeliveryLog::class)
        ->assertSee('listed@example.com')
        ->assertSee(MailWebhooks::token());
});

it('says plainly when the provider will never report anything', function () {
    settings()->set('mail.provider', 'smtp');

    Livewire::actingAs(mailLogUser())
        ->test(EmailDeliveryLog::class)
        ->assertSee('does not report what happens to a message')
        ->assertDontSee(MailWebhooks::token());
});
