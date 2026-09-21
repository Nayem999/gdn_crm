<?php

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Meta\Webhooks\Handlers\MetaChannelHandler;
use App\Domain\Meta\Webhooks\Handlers\WhatsAppHandler;
use App\Domain\Meta\Webhooks\MetaEventKey;
use App\Domain\Meta\Webhooks\MetaEventProcessor;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Domain\Meta\Webhooks\MetaWebhookSignature;
use App\Domain\Settings\SettingsManager;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Jobs\ProcessIntegrationEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Task 12.5 — the door Meta delivers through.
 */
beforeEach(function () {
    app(SettingsManager::class)->set('meta.app_secret', 'the-app-secret');
    app(SettingsManager::class)->set('meta.verify_token', 'the-verify-token');

    // A handler bound by one test must not leak into the next.
    MetaEventProcessor::forgetHandlers();
});

function metaWebhookBody(array $payload): string
{
    return (string) json_encode($payload);
}

function metaSigned(string $body, string $secret = 'the-app-secret'): string
{
    return 'sha256='.hash_hmac('sha256', $body, $secret);
}

function metaLeadGenPayload(string $leadId = '900112233'): array
{
    return [
        'object' => 'page',
        'entry' => [[
            'id' => '1019283746',
            'time' => 1757800000,
            'changes' => [[
                'field' => 'leadgen',
                'value' => [
                    'leadgen_id' => $leadId,
                    'page_id' => '1019283746',
                    'form_id' => '5566778899',
                    'created_time' => 1757800000,
                ],
            ]],
        ]],
    ];
}

function metaPost(MetaChannel $channel, array $payload, ?string $signature = null): TestResponse
{
    $body = metaWebhookBody($payload);

    return test()->call(
        'POST',
        route('api.webhooks.meta', ['channel' => $channel->value]),
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature ?? metaSigned($body)],
        $body
    );
}

// -- The handshake -------------------------------------------------------------

test('the subscription handshake echoes the challenge when the token matches', function () {
    $this->get(route('api.webhooks.meta.verify', ['channel' => 'leadgen']).'?hub_mode=subscribe&hub_verify_token=the-verify-token&hub_challenge=1158201444')
        ->assertOk()
        ->assertSee('1158201444');
});

test('a wrong verify token is refused, whatever challenge it offers', function () {
    // An endpoint that echoed any challenge would let somebody else point their
    // app's webhook at our URL and watch our customers' messages arrive in
    // their delivery log.
    $this->get(route('api.webhooks.meta.verify', ['channel' => 'leadgen']).'?hub_mode=subscribe&hub_verify_token=guessed&hub_challenge=1158201444')
        ->assertForbidden()
        ->assertDontSee('1158201444');
});

test('a handshake for a channel that does not exist is a 404', function () {
    $this->get(route('api.webhooks.meta.verify', ['channel' => 'instagram']).'?hub_mode=subscribe&hub_verify_token=the-verify-token&hub_challenge=x')
        ->assertNotFound();
});

test('an installation with no verify token cannot be subscribed', function () {
    app(SettingsManager::class)->forget('meta.verify_token');

    $this->get(route('api.webhooks.meta.verify', ['channel' => 'leadgen']).'?hub_mode=subscribe&hub_verify_token=&hub_challenge=x')
        ->assertForbidden();
});

// -- The signature -------------------------------------------------------------

test('a delivery with a valid signature is accepted and queued', function () {
    Queue::fake();

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())
        ->assertOk()
        ->assertJsonPath('status', 'received');

    expect(IntegrationEvent::query()->count())->toBe(1);

    Queue::assertPushed(ProcessIntegrationEvent::class);
});

test('a delivery signed with the wrong secret writes nothing', function () {
    Queue::fake();

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload(), metaSigned(metaWebhookBody(metaLeadGenPayload()), 'not-our-secret'))
        ->assertForbidden();

    expect(IntegrationEvent::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('a delivery with no signature at all is refused', function () {
    // The common bug is treating "nothing to compare" as "nothing to object
    // to", which turns the check off for anybody who simply omits it.
    metaPost(MetaChannel::LeadGen, metaLeadGenPayload(), '')
        ->assertForbidden();

    expect(IntegrationEvent::query()->count())->toBe(0);
});

test('the signature is checked against the exact bytes that arrived', function () {
    $body = metaWebhookBody(metaLeadGenPayload());

    expect(MetaWebhookSignature::isValid($body, metaSigned($body), 'the-app-secret'))->toBeTrue()
        // Re-encoding changes whitespace and key order; the signature is over
        // the original.
        ->and(MetaWebhookSignature::isValid($body.' ', metaSigned($body), 'the-app-secret'))->toBeFalse()
        ->and(MetaWebhookSignature::isValid($body, 'sha256=deadbeef', 'the-app-secret'))->toBeFalse()
        ->and(MetaWebhookSignature::isValid($body, hash_hmac('sha256', $body, 'the-app-secret'), 'the-app-secret'))->toBeFalse();
});

test('an installation with no app secret refuses every delivery', function () {
    app(SettingsManager::class)->forget('meta.app_secret');

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertForbidden();
});

// -- Idempotency ---------------------------------------------------------------

test('the same delivery twice produces one event', function () {
    Queue::fake();

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertOk();
    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())
        ->assertOk()
        ->assertJsonPath('status', 'duplicate');

    // Meta retries anything it did not hear a 200 for, and its retries carry
    // the same ids. Without this, one submission is three leads.
    expect(IntegrationEvent::query()->count())->toBe(1);

    Queue::assertPushed(ProcessIntegrationEvent::class, 1);
});

test('two different lead submissions are two events', function () {
    Queue::fake();

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload('111'))->assertOk();
    metaPost(MetaChannel::LeadGen, metaLeadGenPayload('222'))->assertOk();

    expect(IntegrationEvent::query()->count())->toBe(2);
});

test('the event key identifies the thing that happened, not the delivery', function () {
    expect(MetaEventKey::for(MetaChannel::LeadGen, metaWebhookBody(metaLeadGenPayload('900'))))
        ->toBe('leadgen:900');

    $message = metaWebhookBody([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['value' => ['messages' => [['id' => 'wamid.ABC']]]]]]],
    ]);

    expect(MetaEventKey::for(MetaChannel::WhatsApp, $message))->toBe('wamid:wamid.ABC');
});

test('a status change is keyed by the status, because one message reports three', function () {
    $body = fn (string $status) => metaWebhookBody([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.ABC', 'status' => $status]]]]]]],
    ]);

    // sent, delivered and read are three deliveries about one message, and all
    // three are worth keeping.
    expect(MetaEventKey::for(MetaChannel::WhatsApp, $body('sent')))
        ->not->toBe(MetaEventKey::for(MetaChannel::WhatsApp, $body('read')));
});

test('a payload with no id of its own is keyed by its body', function () {
    $key = MetaEventKey::for(MetaChannel::Messenger, metaWebhookBody(['object' => 'page', 'entry' => []]));

    expect($key)->toStartWith('body:');
});

// -- The source ----------------------------------------------------------------

test('a delivery provisions the source it arrives at', function () {
    Queue::fake();

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertOk();

    $source = DataSource::query()->where('provider', 'meta')->firstOrFail();

    expect($source->name)->toBe('Meta — Facebook Lead Ads')
        ->and($source->target_module)->toBe('leads')
        // Meta signs with the app secret, so there is no key of ours to require
        // and no secret of ours to rotate.
        ->and((bool) $source->requires_key)->toBeFalse()
        ->and((bool) $source->requires_signature)->toBeFalse();
});

test('a source somebody deleted comes back rather than swallowing deliveries', function () {
    Queue::fake();

    $source = MetaSources::for(MetaChannel::LeadGen);
    $source->delete();

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertOk();

    expect($source->fresh()->trashed())->toBeFalse()
        ->and(IntegrationEvent::query()->where('data_source_id', $source->id)->count())->toBe(1);
});

test('each channel gets its own source, so one failing does not hide another', function () {
    $leadgen = MetaSources::for(MetaChannel::LeadGen);
    $whatsapp = MetaSources::for(MetaChannel::WhatsApp);

    expect($leadgen->id)->not->toBe($whatsapp->id)
        ->and(MetaSources::channelFor($leadgen))->toBe(MetaChannel::LeadGen)
        ->and(MetaSources::channelFor($whatsapp))->toBe(MetaChannel::WhatsApp);
});

test('an ordinary data source is not mistaken for a managed one', function () {
    $ordinary = DataSource::factory()->create();

    expect(MetaSources::channelFor($ordinary))->toBeNull()
        ->and(MetaSources::isManaged($ordinary))->toBeFalse();
});

// -- Processing ----------------------------------------------------------------

test('a delivery is processed through the channel handler', function () {
    MetaEventProcessor::handle(MetaChannel::LeadGen, MetaWebhookTestHandler::class);

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->fresh()->status())->toBe(IntegrationEventStatus::Processed)
        ->and($event->fresh()->outcome)->toBe('handled');
});

test('a channel with no handler yet is skipped rather than failed', function () {
    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    // A delivery for something this installation cannot yet do is not an error
    // anybody should be paged about, and red in the health panel for a feature
    // that has not shipped is how a health panel stops being read.
    expect($event->fresh()->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->fresh()->outcome)->toBe('no_handler');
});

test('a payload whose object does not match the channel is skipped and recorded', function () {
    MetaEventProcessor::handle(MetaChannel::LeadGen, MetaWebhookTestHandler::class);

    // Meta never crosses its object types, so this did not come from Meta — or
    // came from a subscription pointed at the wrong URL.
    metaPost(MetaChannel::LeadGen, ['object' => 'whatsapp_business_account', 'entry' => []])->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->fresh()->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->fresh()->outcome)->toBe('wrong_object');
});

test('a handler that throws leaves a failed event to retry, not a lost one', function () {
    MetaEventProcessor::handle(MetaChannel::LeadGen, MetaThrowingTestHandler::class);

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->fresh()->status())->toBe(IntegrationEventStatus::Failed)
        ->and($event->fresh()->error)->toContain('Meta was unreachable')
        // The body is kept, which is what makes the replay in 8.9 possible.
        ->and($event->fresh()->payload)->not->toBeEmpty();
});

test('the delivery is recorded before anything looks at it', function () {
    MetaEventProcessor::handle(MetaChannel::LeadGen, MetaThrowingTestHandler::class);

    metaPost(MetaChannel::LeadGen, metaLeadGenPayload())->assertOk();

    // The log records deliveries, not successes: an event that crashed the
    // processor still leaves an account of having arrived.
    expect(IntegrationEvent::query()->count())->toBe(1);
});

/**
 * A handler that succeeds, for the routing tests.
 */
class MetaWebhookTestHandler implements MetaChannelHandler
{
    public function handle(IntegrationEvent $event, array $payload): array
    {
        return ['status' => IntegrationEventStatus::Processed, 'outcome' => 'handled'];
    }
}

/**
 * A handler that fails the way a real one does when Meta is down.
 */
class MetaThrowingTestHandler implements MetaChannelHandler
{
    public function handle(IntegrationEvent $event, array $payload): array
    {
        throw new RuntimeException('Meta was unreachable');
    }
}

// -- Arriving without a worker -------------------------------------------------

test('a message becomes a conversation with no queue worker running', function () {
    // The failure this replaces was silent and happened three times on one
    // installation: the delivery log filled up, the inbox stayed empty, and
    // nothing said the queue had nobody running it.
    config(['ingestion.process' => 'after_response']);

    // The beforeEach above clears the handler registry so tests can bind their
    // own. This one is about the real path, end to end, so the real handler
    // goes back.
    MetaEventProcessor::handle(MetaChannel::WhatsApp, WhatsAppHandler::class);

    $waba = WhatsAppBusinessAccount::factory()->create(['waba_id' => '1405962320928347']);
    WhatsAppPhoneNumber::factory()->create([
        'whatsapp_business_account_id' => $waba->id,
        'phone_number_id' => '1310844125447923',
        'display_number' => '+880 1895-657039',
        'is_default' => true,
    ]);

    metaPost(MetaChannel::WhatsApp, [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '1405962320928347',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '8801895657039', 'phone_number_id' => '1310844125447923'],
                    'contacts' => [['profile' => ['name' => 'Nayem'], 'wa_id' => '8801684191999']],
                    'messages' => [[
                        'from' => '8801684191999',
                        'id' => 'wamid.NOWORKER',
                        'timestamp' => (string) now()->timestamp,
                        'text' => ['body' => 'Is anyone there?'],
                        'type' => 'text',
                    ]],
                ],
            ]],
        ]],
    ])->assertOk();

    // Both tables, which is the whole point: the log records the delivery
    // inside the request, and the conversation is threaded once the response
    // has gone — with nothing else running.
    expect(IntegrationEvent::query()->latest('id')->first()->status())->toBe(IntegrationEventStatus::Processed)
        ->and(SocialMessage::query()->where('external_message_id', 'wamid.NOWORKER')->exists())->toBeTrue()
        ->and(SocialConversation::query()->where('participant_external_id', '8801684191999')->exists())->toBeTrue();
});

test('an installation with a worker can still hand deliveries to the queue', function () {
    config(['ingestion.process' => 'queue']);
    Queue::fake();

    metaPost(MetaChannel::WhatsApp, metaLeadGenPayload())->assertOk();

    // Retries, backoff and work off the web tier are better where there is
    // something to do the work — so the choice stays available.
    Queue::assertPushed(ProcessIntegrationEvent::class);
});
