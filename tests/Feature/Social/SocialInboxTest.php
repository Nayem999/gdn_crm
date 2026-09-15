<?php

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Settings\SettingsManager;
use App\Domain\Social\Actions\AssignConversationAction;
use App\Domain\Social\Actions\SendSocialMessageAction;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\MessagingWindow;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Domain\Timeline\Communications\CommunicationChannel;
use App\Domain\Timeline\Communications\CommunicationGatherer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Task 12.8 — Messenger threads, and the rules about answering them.
 */
beforeEach(function () {
    app(SettingsManager::class)->set('meta.app_secret', 'the-app-secret');
});

function socialPage(array $attributes = []): MetaPage
{
    return MetaPage::factory()->create(['page_id' => '1019283746', ...$attributes]);
}

/**
 * A Messenger webhook, as Meta sends it.
 */
function socialEnvelope(array $overrides = []): array
{
    return [
        'object' => 'page',
        'entry' => [[
            'id' => '1019283746',
            'time' => 1757840000000,
            'messaging' => [[
                'sender' => ['id' => 'PSID-4417'],
                'recipient' => ['id' => '1019283746'],
                'timestamp' => 1757840000000,
                'message' => [
                    'mid' => 'mid.ABC123',
                    'text' => 'Do you deliver to Bristol?',
                ],
                ...$overrides,
            ]],
        ]],
    ];
}

function socialDeliver(array $payload): TestResponse
{
    $body = (string) json_encode($payload);

    return test()->call(
        'POST',
        route('api.webhooks.meta', ['channel' => 'messenger']),
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-app-secret'),
        ],
        $body
    );
}

// -- Threading -------------------------------------------------------------------

test('an inbound message threads onto a conversation', function () {
    socialPage();

    socialDeliver(socialEnvelope())->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();

    expect($conversation->channel())->toBe(SocialChannel::Messenger)
        ->and($conversation->external_conversation_id)->toBe('PSID-4417')
        ->and($conversation->channel_account_id)->toBe('1019283746')
        ->and($conversation->unread_count)->toBe(1)
        ->and($conversation->status())->toBe(ConversationStatus::Open);

    $message = SocialMessage::query()->firstOrFail();

    expect($message->body)->toBe('Do you deliver to Bristol?')
        ->and($message->direction())->toBe(MessageDirection::Inbound)
        ->and($message->external_message_id)->toBe('mid.ABC123');
});

test('a second message from the same person joins the same conversation', function () {
    socialPage();

    socialDeliver(socialEnvelope())->assertOk();
    socialDeliver(socialEnvelope([
        'message' => ['mid' => 'mid.DEF456', 'text' => 'Still there?'],
    ]))->assertOk();

    // One thread, two messages. A second conversation is the failure everybody
    // notices, because the reply goes into the wrong half of it.
    expect(SocialConversation::query()->count())->toBe(1)
        ->and(SocialMessage::query()->count())->toBe(2)
        ->and(SocialConversation::query()->value('unread_count'))->toBe(2);
});

test('the same message delivered twice is recorded once', function () {
    socialPage();

    socialDeliver(socialEnvelope())->assertOk();

    // Meta retries anything it did not hear a 200 for, and the retry carries the
    // same mid. The webhook door refuses the repeat; the recorder would refuse
    // it again behind that.
    expect(SocialMessage::query()->count())->toBe(1)
        ->and(SocialConversation::query()->value('unread_count'))->toBe(1);
});

test('two different people get two conversations', function () {
    socialPage();

    socialDeliver(socialEnvelope())->assertOk();
    socialDeliver(socialEnvelope([
        'sender' => ['id' => 'PSID-9999'],
        'message' => ['mid' => 'mid.ZZZ', 'text' => 'Hello'],
    ]))->assertOk();

    expect(SocialConversation::query()->count())->toBe(2);
});

test('a customer writing again reopens a closed conversation', function () {
    socialPage();

    socialDeliver(socialEnvelope())->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();
    app(AssignConversationAction::class)->close($conversation);

    socialDeliver(socialEnvelope([
        'message' => ['mid' => 'mid.NEW', 'text' => 'Any update?'],
    ]))->assertOk();

    // A message nobody sees because it landed in a thread somebody had ticked
    // off is the failure this prevents.
    expect($conversation->fresh()?->status())->toBe(ConversationStatus::Open);
});

// -- What is not a message --------------------------------------------------------

test('an echo of our own reply is not recorded as the customer speaking', function () {
    socialPage();

    socialDeliver(socialEnvelope([
        'message' => ['mid' => 'mid.ECHO', 'text' => 'Yes, we deliver to Bristol.', 'is_echo' => true],
    ]))->assertOk();

    // Meta sends the page's own messages back on the same subscription. Without
    // this every agent reply appears twice and reopens the thread.
    expect(SocialMessage::query()->count())->toBe(0)
        ->and(IntegrationEvent::query()->firstOrFail()->outcome)->toBe('not_a_message');
});

test('a delivery receipt carries no message and threads nothing', function () {
    socialPage();

    $payload = socialEnvelope();
    unset($payload['entry'][0]['messaging'][0]['message']);
    $payload['entry'][0]['messaging'][0]['delivery'] = ['mids' => ['mid.ABC123'], 'watermark' => 1757840000000];

    socialDeliver($payload)->assertOk();

    expect(SocialMessage::query()->count())->toBe(0)
        ->and(SocialConversation::query()->count())->toBe(0);
});

test('a page this installation does not manage is skipped, not failed', function () {
    socialDeliver(socialEnvelope())->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->outcome)->toBe('unknown_page')
        ->and(SocialConversation::query()->count())->toBe(0);
});

test('an attachment with no text still reads as something', function () {
    socialPage();

    socialDeliver(socialEnvelope([
        'message' => [
            'mid' => 'mid.IMG',
            'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.example.com/a.jpg']]],
        ],
    ]))->assertOk();

    $message = SocialMessage::query()->firstOrFail();

    expect($message->type())->toBe(MessageType::Image)
        ->and($message->body)->toBeNull()
        // An empty bubble is indistinguishable from a bug.
        ->and($message->preview())->toBe('Image');
});

// -- Becoming a lead ---------------------------------------------------------------

test('an unknown sender becomes a lead, attributed to the channel', function () {
    $page = socialPage();

    socialDeliver(socialEnvelope())->assertOk();

    $lead = Lead::query()->firstOrFail();

    expect($lead->source)->toBe(LeadSource::FacebookMessenger->value)
        // Owned by whoever connected Meta: a lead owned by nobody is how the
        // visibility scope springs a leak.
        ->and($lead->owner_id)->toBe($page->account->connected_by_id)
        ->and($lead->attribution()->source)->toBe(LeadSource::FacebookMessenger->value)
        ->and(SocialConversation::query()->value('lead_id'))->toBe($lead->id);
});

test('a second message does not make a second lead', function () {
    socialPage();

    socialDeliver(socialEnvelope())->assertOk();
    socialDeliver(socialEnvelope([
        'message' => ['mid' => 'mid.TWO', 'text' => 'Hello again'],
    ]))->assertOk();

    expect(Lead::query()->count())->toBe(1);
});

test('a conversation already tied to a lead is left tied to it', function () {
    socialPage();

    $existing = Lead::factory()->create();

    SocialConversation::factory()->forLead($existing)->create([
        'external_conversation_id' => 'PSID-4417',
        'channel' => SocialChannel::Messenger->value,
    ]);

    socialDeliver(socialEnvelope())->assertOk();

    expect(Lead::query()->count())->toBe(1)
        ->and(SocialConversation::query()->value('lead_id'))->toBe($existing->id);
});

// -- Meta's reply window -------------------------------------------------------------

test('the window is measured from the customer message, not ours', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    socialPage();
    socialDeliver(socialEnvelope(['timestamp' => Carbon::now()->getTimestampMs()]))->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();

    // Messenger gives seven days from their last message.
    expect($conversation->window_expires_at?->toDateTimeString())->toBe('2026-09-22 12:00:00')
        ->and($conversation->isWindowOpen())->toBeTrue();

    Carbon::setTestNow();
});

test('a reply inside the window is sent and recorded', function () {
    $page = socialPage();
    $agent = User::factory()->create();

    $conversation = SocialConversation::factory()->create([
        'channel' => SocialChannel::Messenger->value,
        'channel_account_id' => $page->page_id,
        'external_conversation_id' => 'PSID-4417',
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.SENT'])]);

    $message = app(SendSocialMessageAction::class)($conversation, 'Yes, next-day to Bristol.', $agent);

    expect($message->direction())->toBe(MessageDirection::Outbound)
        ->and($message->status())->toBe(MessageStatus::Sent)
        ->and($message->external_message_id)->toBe('mid.SENT')
        ->and($message->sender_user_id)->toBe($agent->id);

    // Answered, so it is waiting on them and no longer unread.
    expect($conversation->fresh()?->status())->toBe(ConversationStatus::Pending)
        ->and($conversation->fresh()?->unread_count)->toBe(0);
});

test('a reply outside the window is refused with Meta\'s reason', function () {
    $page = socialPage();

    $conversation = SocialConversation::factory()->windowClosed()->create([
        'channel' => SocialChannel::Messenger->value,
        'channel_account_id' => $page->page_id,
    ]);

    Http::fake();

    expect(fn () => app(SendSocialMessageAction::class)($conversation, 'Still interested?', User::factory()->create()))
        // Meta's own words, so somebody can find the rule rather than guess.
        ->toThrow(RuntimeException::class, '7-day window closed');

    // Nothing was said to the customer, and nothing pretends it was.
    Http::assertNothingSent();
    expect(SocialMessage::query()->count())->toBe(0);
});

test('a reply does not extend the window', function () {
    Carbon::setTestNow('2026-09-15 12:00:00');

    $page = socialPage();
    $conversation = SocialConversation::factory()->create([
        'channel' => SocialChannel::Messenger->value,
        'channel_account_id' => $page->page_id,
        'last_message_at' => Carbon::now()->subDays(6),
        'window_expires_at' => Carbon::now()->addDay(),
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.X'])]);

    app(SendSocialMessageAction::class)($conversation, 'Checking in.', User::factory()->create());

    // Meta measures from the customer's message. Extending it here would make
    // the inbox believe a thread is open long after Meta has closed it.
    expect($conversation->fresh()?->window_expires_at?->toDateTimeString())
        ->toBe(Carbon::parse('2026-09-16 12:00:00')->toDateTimeString());

    Carbon::setTestNow();
});

test('a conversation nobody has written to has no window at all', function () {
    $conversation = SocialConversation::factory()->neverInbound()->create();

    expect($conversation->isWindowOpen())->toBeFalse()
        ->and($conversation->windowRefusal())->toContain('has not messaged us');
});

test('the window rule states each channel\'s own number', function () {
    $opened = Carbon::parse('2026-09-15 12:00:00');

    expect(MessagingWindow::expiresAt(SocialChannel::Messenger, $opened)->toDateTimeString())
        ->toBe('2026-09-22 12:00:00')
        ->and(MessagingWindow::expiresAt(SocialChannel::WhatsApp, $opened)->toDateTimeString())
        ->toBe('2026-09-16 12:00:00');

    // Their words for the window and for what may still be sent, so an agent
    // looks in the right documentation. "24-hour" rather than the arithmetically
    // identical "1-day", which matches nothing anybody can search for.
    expect(MessagingWindow::refusal(SocialChannel::WhatsApp, $opened->copy()->subDay()))
        ->toContain('24-hour')
        ->toContain('an approved template')
        ->and(MessagingWindow::refusal(SocialChannel::Messenger, $opened->copy()->subDay()))
        ->toContain('7-day')
        ->toContain('a message tag');
});

test('a send Meta refuses leaves the attempt and the reason', function () {
    $page = socialPage();

    $conversation = SocialConversation::factory()->create([
        'channel' => SocialChannel::Messenger->value,
        'channel_account_id' => $page->page_id,
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Outside allowed window', 'code' => 10]], 400)]);

    expect(fn () => app(SendSocialMessageAction::class)($conversation, 'Hello?', User::factory()->create()))
        ->toThrow(RuntimeException::class);

    // An agent who typed three paragraphs needs to see the attempt and the
    // reason, not an empty thread.
    $message = SocialMessage::query()->firstOrFail();

    expect($message->status())->toBe(MessageStatus::Failed)
        ->and($message->body)->toBe('Hello?')
        ->and($message->error)->toContain('Outside allowed window');
});

test('a page that lost its token refuses rather than failing at Meta', function () {
    $page = MetaPage::factory()->unconnected()->create(['page_id' => '1019283746']);

    $conversation = SocialConversation::factory()->create([
        'channel' => SocialChannel::Messenger->value,
        'channel_account_id' => $page->page_id,
    ]);

    Http::fake();

    expect(fn () => app(SendSocialMessageAction::class)($conversation, 'Hello', User::factory()->create()))
        ->toThrow(RuntimeException::class, 'Reconnect it');

    Http::assertNothingSent();
});

// -- Assignment --------------------------------------------------------------------

test('claiming a conversation records who has it', function () {
    $agent = User::factory()->create();
    $conversation = SocialConversation::factory()->unread()->create();

    app(AssignConversationAction::class)->assign($conversation, $agent);

    expect($conversation->fresh()?->assigned_to_id)->toBe($agent->id);
});

test('closing marks it read, and releasing leaves the status alone', function () {
    $agent = User::factory()->create();
    $conversation = SocialConversation::factory()->unread()->assignedTo($agent)->create();

    app(AssignConversationAction::class)->close($conversation);

    expect($conversation->fresh()?->status())->toBe(ConversationStatus::Closed)
        ->and($conversation->fresh()?->unread_count)->toBe(0);

    app(AssignConversationAction::class)->reopen($conversation->fresh());
    $released = app(AssignConversationAction::class)->release($conversation->fresh());

    // A thread nobody is holding is not thereby unanswered.
    expect($released->assigned_to_id)->toBeNull()
        ->and($released->status())->toBe(ConversationStatus::Open);
});

// -- The timeline --------------------------------------------------------------------

test('the conversation appears on the lead\'s timeline', function () {
    socialPage();

    socialDeliver(socialEnvelope())->assertOk();

    $lead = Lead::query()->firstOrFail();

    $entries = app(CommunicationGatherer::class)->for($lead, 20);

    $social = collect($entries)->firstWhere('channel', CommunicationChannel::Messenger);

    expect($social)->not->toBeNull()
        ->and($social?->body)->toContain('Bristol')
        // One entry for the conversation, not one per message: thirty rows
        // would bury every other strand on the page.
        ->and(collect($entries)->where('channel', CommunicationChannel::Messenger)->count())
        ->toBe(1);
});
