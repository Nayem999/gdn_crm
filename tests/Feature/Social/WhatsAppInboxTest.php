<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Settings\SettingsManager;
use App\Domain\Social\Actions\FetchWhatsAppMediaAction;
use App\Domain\Social\Actions\SendSocialMessageAction;
use App\Domain\Social\Actions\SendWhatsAppTemplateAction;
use App\Domain\Social\Actions\SyncWhatsAppTemplatesAction;
use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Enums\TemplateStatus;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Domain\Social\Models\WhatsAppTemplate;
use App\Jobs\FetchWhatsAppMedia;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Task 12.10 — WhatsApp: threading, templates, receipts and media.
 */
beforeEach(function () {
    app(SettingsManager::class)->set('meta.app_secret', 'the-app-secret');
});

/**
 * A connected number, with the business account and token behind it.
 */
function waNumber(array $attributes = []): WhatsAppPhoneNumber
{
    $account = MetaAccount::factory()->create();

    $waba = WhatsAppBusinessAccount::query()->create([
        'meta_account_id' => $account->id,
        'waba_id' => '99887766',
        'name' => 'Golden Infotech WhatsApp',
        'access_token' => 'EAAWabaToken',
        'is_subscribed' => true,
    ]);

    return WhatsAppPhoneNumber::query()->create([
        'whatsapp_business_account_id' => $waba->id,
        'phone_number_id' => '556677889900',
        'display_number' => '+44 117 000 0000',
        'verified_name' => 'Golden Infotech',
        'is_default' => true,
        ...$attributes,
    ]);
}

/**
 * A Cloud API webhook, as Meta sends it.
 */
function waEnvelope(array $value = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '99887766',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '441170000000',
                        'phone_number_id' => '556677889900',
                    ],
                    ...$value,
                ],
            ]],
        ]],
    ];
}

/**
 * One inbound text message from a customer.
 */
function waMessage(array $overrides = []): array
{
    return [
        'contacts' => [['profile' => ['name' => 'Dara Okafor'], 'wa_id' => '447700900123']],
        'messages' => [[
            'from' => '447700900123',
            'id' => 'wamid.HBgM1',
            // WhatsApp counts in seconds, unlike Messenger's milliseconds.
            'timestamp' => (string) Carbon::parse('2026-09-15 09:00:00')->getTimestamp(),
            'type' => 'text',
            'text' => ['body' => 'Is the 3-tonne digger available Saturday?'],
            ...$overrides,
        ]],
    ];
}

function waDeliver(array $payload): TestResponse
{
    $body = (string) json_encode($payload);

    return test()->call(
        'POST',
        route('api.webhooks.meta', ['channel' => 'whatsapp']),
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-app-secret'),
        ],
        $body
    );
}

// -- Threading -------------------------------------------------------------------

test('a new number creates a lead and a conversation', function () {
    $number = waNumber();

    waDeliver(waEnvelope(waMessage()))->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();

    expect($conversation->channel())->toBe(SocialChannel::WhatsApp)
        // The customer's number is the thread.
        ->and($conversation->external_conversation_id)->toBe('447700900123')
        ->and($conversation->channel_account_id)->toBe('556677889900')
        // WhatsApp gives the profile name, which Messenger's webhook does not.
        ->and($conversation->participant_name)->toBe('Dara Okafor')
        ->and($conversation->participant_handle)->toBe('+447700900123');

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Dara')
        ->and($lead->source)->toBe(LeadSource::WhatsApp->value)
        ->and($conversation->lead_id)->toBe($lead->id)
        ->and($lead->owner_id)->toBe($number->businessAccount->account->connected_by_id);
});

test('a known number attaches to the existing contact rather than making a lead', function () {
    waNumber();

    // The same line, as somebody here typed it years ago. The duplicate engine
    // compares the last nine digits, so the two meet.
    $contact = Contact::factory()->create(['phone' => '07700 900123']);

    waDeliver(waEnvelope(waMessage()))->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();

    expect($conversation->contact_id)->toBe($contact->id)
        ->and($conversation->lead_id)->toBeNull()
        // A customer of ten years does not become a stranger because they
        // switched channel.
        ->and(Lead::query()->count())->toBe(0);
});

test('a known number on a lead threads onto that lead', function () {
    waNumber();

    $lead = Lead::factory()->create(['phone' => '+44 7700 900123', 'mobile' => null]);

    waDeliver(waEnvelope(waMessage()))->assertOk();

    expect(SocialConversation::query()->value('lead_id'))->toBe($lead->id)
        ->and(Lead::query()->count())->toBe(1);
});

test('the 24-hour window is measured from their message', function () {
    waNumber();

    waDeliver(waEnvelope(waMessage()))->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();

    expect($conversation->window_expires_at?->toDateTimeString())->toBe('2026-09-16 09:00:00');
});

test('a number this installation does not have is skipped', function () {
    waDeliver(waEnvelope(waMessage()))->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->outcome)->toBe('unknown_number')
        ->and(SocialConversation::query()->count())->toBe(0);
});

// -- Receipts ---------------------------------------------------------------------

test('receipts move an outbound message forward', function () {
    waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => '556677889900',
        'external_conversation_id' => '447700900123',
    ]);

    $message = SocialMessage::factory()->inConversation($conversation)->outbound()->create([
        'external_message_id' => 'wamid.OUT1',
        'status' => MessageStatus::Sent->value,
    ]);

    waDeliver(waEnvelope(['statuses' => [[
        'id' => 'wamid.OUT1',
        'status' => 'delivered',
        'timestamp' => (string) Carbon::now()->getTimestamp(),
        'recipient_id' => '447700900123',
    ]]]))->assertOk();

    expect($message->fresh()?->status())->toBe(MessageStatus::Delivered)
        ->and($message->fresh()?->delivered_at)->not->toBeNull();

    expect(IntegrationEvent::query()->latest('id')->value('outcome'))->toBe('receipted');
});

test('a late delivery receipt does not undo a read one', function () {
    waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => '556677889900',
    ]);

    $message = SocialMessage::factory()->inConversation($conversation)->outbound()->create([
        'external_message_id' => 'wamid.OUT2',
        'status' => MessageStatus::Read->value,
    ]);

    // Meta's receipts overtake each other routinely. A message somebody has
    // already read must not slide back to merely delivered.
    waDeliver(waEnvelope(['statuses' => [[
        'id' => 'wamid.OUT2',
        'status' => 'delivered',
        'timestamp' => (string) Carbon::now()->getTimestamp(),
    ]]]))->assertOk();

    expect($message->fresh()?->status())->toBe(MessageStatus::Read);
});

test('a failure receipt wins even after a read one, and says why', function () {
    waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => '556677889900',
    ]);

    $message = SocialMessage::factory()->inConversation($conversation)->outbound()->create([
        'external_message_id' => 'wamid.OUT3',
        'status' => MessageStatus::Read->value,
    ]);

    waDeliver(waEnvelope(['statuses' => [[
        'id' => 'wamid.OUT3',
        'status' => 'failed',
        'timestamp' => (string) Carbon::now()->getTimestamp(),
        'errors' => [['title' => 'Message undeliverable']],
    ]]]))->assertOk();

    expect($message->fresh()?->status())->toBe(MessageStatus::Failed)
        ->and($message->fresh()?->error)->toContain('Message undeliverable');
});

test('a receipt for a message this application never sent is ignored', function () {
    waNumber();

    waDeliver(waEnvelope(['statuses' => [[
        'id' => 'wamid.SENT-FROM-A-PHONE',
        'status' => 'delivered',
        'timestamp' => (string) Carbon::now()->getTimestamp(),
    ]]]))->assertOk();

    // Meta reports on everything the number sent, including messages somebody
    // sent from the Business Manager. Those are not ours to account for.
    expect(IntegrationEvent::query()->firstOrFail()->status())->toBe(IntegrationEventStatus::Skipped);
});

// -- Sending ----------------------------------------------------------------------

test('a free-form reply inside the window goes through the Cloud API', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
        'external_conversation_id' => '447700900123',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NEW']]])]);

    $message = app(SendSocialMessageAction::class)($conversation, 'Yes, it is free on Saturday.', User::factory()->create());

    expect($message->status())->toBe(MessageStatus::Sent)
        ->and($message->external_message_id)->toBe('wamid.NEW');

    Http::assertSent(fn ($request) => str_contains($request->url(), '556677889900/messages')
        && $request['messaging_product'] === 'whatsapp'
        && $request['to'] === '447700900123');
});

test('a free-form reply outside the window is refused with Meta\'s reason', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->windowClosed()->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    Http::fake();

    expect(fn () => app(SendSocialMessageAction::class)($conversation, 'Still interested?', User::factory()->create()))
        ->toThrow(RuntimeException::class, '24-hour window closed');

    Http::assertNothingSent();
});

// -- Templates ---------------------------------------------------------------------

test('templates are read from Meta with their status and placeholders', function () {
    $number = waNumber();

    Http::fake(['graph.facebook.com/*message_templates*' => Http::response(['data' => [
        [
            'name' => 'hire_confirmation',
            'language' => 'en_GB',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'text' => 'Your hire'],
                ['type' => 'BODY', 'text' => 'Hello {{1}}, your {{2}} is booked for {{3}}.'],
                ['type' => 'FOOTER', 'text' => 'Golden Infotech'],
            ],
        ],
        [
            'name' => 'winter_offer',
            'language' => 'en_GB',
            'category' => 'MARKETING',
            'status' => 'REJECTED',
            'rejected_reason' => 'Promotional content in a utility template.',
            'components' => [['type' => 'BODY', 'text' => 'Save 20% this winter.']],
        ],
    ]])]);

    $counts = app(SyncWhatsAppTemplatesAction::class)($number->businessAccount);

    expect($counts)->toMatchArray(['templates' => 2, 'approved' => 1]);

    $approved = WhatsAppTemplate::query()->where('name', 'hire_confirmation')->firstOrFail();

    expect($approved->status())->toBe(TemplateStatus::Approved)
        ->and($approved->header)->toBe('Your hire')
        // Read from the body rather than Meta's optional example block.
        ->and($approved->variables)->toBe(['1', '2', '3'])
        ->and($approved->waba_id)->toBe('99887766');

    $rejected = WhatsAppTemplate::query()->where('name', 'winter_offer')->firstOrFail();

    expect($rejected->status())->toBe(TemplateStatus::Rejected)
        // The only thing that tells somebody what to change.
        ->and($rejected->rejection_reason)->toContain('Promotional content');
});

test('a re-read updates a template rather than duplicating it', function () {
    $number = waNumber();

    Http::fake([
        'graph.facebook.com/*message_templates*' => Http::sequence()
            ->push(['data' => [['name' => 'hire_confirmation', 'language' => 'en_GB', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}.']]]]])
            // Meta paused it, without telling anybody.
            ->push(['data' => [['name' => 'hire_confirmation', 'language' => 'en_GB', 'status' => 'PAUSED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}.']]]]]),
    ]);

    app(SyncWhatsAppTemplatesAction::class)($number->businessAccount);
    app(SyncWhatsAppTemplatesAction::class)($number->businessAccount);

    expect(WhatsAppTemplate::query()->count())->toBe(1)
        ->and(WhatsAppTemplate::query()->firstOrFail()->status())->toBe(TemplateStatus::Paused);
});

test('the same name in two languages is two templates', function () {
    WhatsAppTemplate::factory()->create(['name' => 'hire_confirmation', 'language' => 'en_GB']);
    WhatsAppTemplate::factory()->create(['name' => 'hire_confirmation', 'language' => 'bn_BD']);

    // Meta approves each translation separately, so they are separate rows.
    expect(WhatsAppTemplate::query()->where('name', 'hire_confirmation')->count())->toBe(2);
});

test('a template sends outside the window, by name and parameters', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->windowClosed()->create([
        'channel_account_id' => $number->phone_number_id,
        'external_conversation_id' => '447700900123',
    ]);

    $template = WhatsAppTemplate::factory()->create([
        'waba_id' => '99887766',
        'name' => 'hire_confirmation',
        'body' => 'Hello {{1}}, your {{2}} is booked.',
        'variables' => ['1', '2'],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL']]])]);

    $message = app(SendWhatsAppTemplateAction::class)(
        $conversation,
        $template,
        ['Dara', '3-tonne digger'],
        User::factory()->create(),
    );

    expect($message->type())->toBe(MessageType::Template)
        ->and($message->template_name)->toBe('hire_confirmation')
        ->and($message->status())->toBe(MessageStatus::Sent)
        // The thread shows what the customer will read; what went to Meta was
        // the name and the values.
        ->and($message->body)->toBe('Hello Dara, your 3-tonne digger is booked.');

    Http::assertSent(function ($request) {
        $template = $request['template'];

        return $request['type'] === 'template'
            && $template['name'] === 'hire_confirmation'
            && $template['components'][0]['parameters'][0]['text'] === 'Dara';
    });
});

test('an unapproved template cannot be sent', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->windowClosed()->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $template = WhatsAppTemplate::factory()->withStatus(TemplateStatus::Pending)->create(['waba_id' => '99887766']);

    Http::fake();

    expect(fn () => app(SendWhatsAppTemplateAction::class)($conversation, $template, ['a', 'b'], User::factory()->create()))
        ->toThrow(RuntimeException::class, 'has not finished reviewing');

    Http::assertNothingSent();
    expect(SocialMessage::query()->count())->toBe(0);
});

test('a template Meta paused says so rather than failing at Meta', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $template = WhatsAppTemplate::factory()->withStatus(TemplateStatus::Paused)->create(['waba_id' => '99887766']);

    Http::fake();

    expect(fn () => app(SendWhatsAppTemplateAction::class)($conversation, $template, ['a', 'b'], User::factory()->create()))
        ->toThrow(RuntimeException::class, 'paused this template');

    Http::assertNothingSent();
});

test('a template\'s variables are validated before Meta is asked', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $template = WhatsAppTemplate::factory()->create([
        'waba_id' => '99887766',
        'name' => 'hire_confirmation',
        'variables' => ['1', '2'],
    ]);

    Http::fake();

    // Meta's own answer to this is the code 132000, which tells an agent
    // nothing.
    expect(fn () => app(SendWhatsAppTemplateAction::class)($conversation, $template, ['Dara'], User::factory()->create()))
        ->toThrow(RuntimeException::class, 'needs 2 values; 1 was given');

    Http::assertNothingSent();
});

test('a template with no placeholders sends without a components block', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $template = WhatsAppTemplate::factory()->withoutVariables()->create(['waba_id' => '99887766']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.PLAIN']]])]);

    app(SendWhatsAppTemplateAction::class)($conversation, $template, [], User::factory()->create());

    // Meta refuses a components array carrying an empty parameter list.
    Http::assertSent(fn ($request) => ! array_key_exists('components', $request['template']));
});

test('a template approved for another business account is refused', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $template = WhatsAppTemplate::factory()->create(['waba_id' => 'SOMEBODY-ELSE']);

    Http::fake();

    expect(fn () => app(SendWhatsAppTemplateAction::class)($conversation, $template, ['a', 'b'], User::factory()->create()))
        ->toThrow(RuntimeException::class, 'different WhatsApp business account');

    Http::assertNothingSent();
});

// -- Media --------------------------------------------------------------------------

test('an attachment is queued for fetching rather than pulled on the webhook', function () {
    waNumber();

    // Only this job is faked. Faking the whole queue would also hold back
    // ProcessIntegrationEvent, and the delivery would never be threaded at all.
    Queue::fake([FetchWhatsAppMedia::class]);

    waDeliver(waEnvelope([
        'contacts' => [['profile' => ['name' => 'Dara Okafor'], 'wa_id' => '447700900123']],
        'messages' => [[
            'from' => '447700900123',
            'id' => 'wamid.IMG',
            'timestamp' => (string) Carbon::now()->getTimestamp(),
            'type' => 'image',
            'image' => ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg', 'caption' => 'The broken part'],
        ]],
    ]))->assertOk();

    $message = SocialMessage::query()->firstOrFail();

    expect($message->type())->toBe(MessageType::Image)
        // The caption is the body, so the thread reads as something immediately.
        ->and($message->body)->toBe('The broken part')
        ->and($message->attachments[0]['media_id'])->toBe('MEDIA-1');

    // Meta gives a webhook seconds; a customer's video would time it out, and a
    // timed-out delivery is retried and duplicated.
    Queue::assertPushed(FetchWhatsAppMedia::class);
});

test('fetching an attachment stores it on the private disk', function () {
    Storage::fake('local');

    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $message = SocialMessage::factory()->inConversation($conversation)->create([
        'type' => MessageType::Image->value,
        'attachments' => [['type' => 'image', 'media_id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']],
    ]);

    Http::fake([
        // Meta answers a media id with a short-lived URL on a lookaside host.
        'graph.facebook.com/*MEDIA-1*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/MEDIA-1', 'mime_type' => 'image/jpeg']),
        'lookaside.fbsbx.com/*' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    expect(app(FetchWhatsAppMediaAction::class)($message))->toBeTrue();

    $stored = $message->fresh()?->attachmentFile();

    expect($stored)->not->toBeNull()
        // A name of ours: a filename from a stranger has no business deciding a
        // path.
        ->and($stored?->file_name)->toStartWith('whatsapp-')
        ->and($stored?->file_name)->toEndWith('.jpg');
});

test('an attachment already fetched is not fetched twice', function () {
    Storage::fake('local');

    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $message = SocialMessage::factory()->inConversation($conversation)->create([
        'type' => MessageType::Image->value,
        'attachments' => [['type' => 'image', 'media_id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']],
    ]);

    Http::fake([
        'graph.facebook.com/*MEDIA-1*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/MEDIA-1']),
        'lookaside.fbsbx.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    app(FetchWhatsAppMediaAction::class)($message);

    expect(app(FetchWhatsAppMediaAction::class)($message->fresh()))->toBeFalse();
});

test('an attachment Meta will not hand over leaves the message readable', function () {
    Storage::fake('local');

    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $message = SocialMessage::factory()->inConversation($conversation)->create([
        'body' => 'The broken part',
        'type' => MessageType::Image->value,
        'attachments' => [['type' => 'image', 'media_id' => 'MEDIA-GONE']],
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Media not found']], 404)]);

    // Not an exception: the message is threaded and readable, and failing the
    // whole delivery would invite a replay that duplicates nothing useful.
    expect(app(FetchWhatsAppMediaAction::class)($message))->toBeFalse()
        ->and($message->fresh()?->body)->toBe('The broken part');
});

test('an attachment is streamed only to somebody who may see the conversation', function () {
    Storage::fake('local');

    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
    ]);

    $message = SocialMessage::factory()->inConversation($conversation)->create([
        'type' => MessageType::Image->value,
        'attachments' => [['type' => 'image', 'media_id' => 'MEDIA-1', 'mime_type' => 'image/jpeg']],
    ]);

    Http::fake([
        'graph.facebook.com/*MEDIA-1*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/MEDIA-1']),
        'lookaside.fbsbx.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    app(FetchWhatsAppMediaAction::class)($message);

    // Somebody with no business in the inbox. The bytes are customer
    // photographs, signed paperwork and occasionally identity documents.
    test()->actingAs(User::factory()->create())
        ->get(route('social.media', $message))
        ->assertForbidden();

    test()->actingAs(socialAgentFor(['social.inbox.view']))
        ->get(route('social.media', $message))
        ->assertOk()
        // Never inline: a customer can send an HTML file, and rendering one on
        // this origin is how it would run script here.
        ->assertHeader('content-disposition', 'attachment; filename='.$message->fresh()?->attachmentFile()?->file_name);
});

/**
 * A user holding exactly the given inbox permissions.
 */
function socialAgentFor(array $permissions): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

test('a revoked token is reported as revoked, not in the words Meta chose', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
        'external_conversation_id' => '447700900123',
    ]);

    // Exactly what Meta answers for a system user token whose authorisation
    // has been withdrawn. Its wording names an app id and reads as though the
    // wrong app were configured, which is not what happened and not what fixes
    // it — it sent somebody looking for a second Meta app that did not exist.
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => [
            'message' => 'Error validating access token: The user has not authorized application 1772978827247269.',
            'type' => 'OAuthException',
            'code' => 190,
            'error_subcode' => 458,
        ],
    ], 400)]);

    expect(fn () => app(SendSocialMessageAction::class)($conversation, 'Are you still there?', User::factory()->create()))
        ->toThrow(RuntimeException::class, 'no longer authorised');

    expect(fn () => app(SendSocialMessageAction::class)($conversation, 'Are you still there?', User::factory()->create()))
        ->not->toThrow(RuntimeException::class, 'has not authorized application');
});

test('a refusal about the message itself keeps the words Meta chose', function () {
    $number = waNumber();

    $conversation = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'channel_account_id' => $number->phone_number_id,
        'external_conversation_id' => '447700900123',
    ]);

    // Here Meta's prose is the useful part, and replacing it with ours would
    // throw away the only sentence that says what to change.
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => [
            'message' => 'Template name does not exist in the translation',
            'type' => 'OAuthException',
            'code' => 132001,
        ],
    ], 400)]);

    expect(fn () => app(SendSocialMessageAction::class)($conversation, 'Hello', User::factory()->create()))
        ->toThrow(RuntimeException::class, 'Template name does not exist');
});
