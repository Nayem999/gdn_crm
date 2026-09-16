<?php

use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Settings\SettingsManager;
use App\Domain\Social\Actions\ImportMessengerHistoryAction;
use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Importing the conversations a Page had before this CRM was connected.
 *
 * Webhooks only deliver what arrives after they are subscribed, so a business
 * that has been answering customers for years connects the CRM and sees an
 * empty inbox. Meta will hand over a Page's history — and, importantly, will
 * not hand over WhatsApp's.
 */
function importPage(): MetaPage
{
    return MetaPage::query()->create([
        'meta_account_id' => MetaAccount::factory()->create()->id,
        'page_id' => '106069340959538',
        'name' => 'Golden Info Systems Ltd.',
        'access_token' => 'page-token-value',
        'is_subscribed' => true,
    ]);
}

/**
 * Meta's own shape for `/{page-id}/conversations`.
 *
 * @param  array<int, array<string, mixed>>  $messages
 * @return array<string, mixed>
 */
function importConversation(string $customerId, string $customerName, array $messages): array
{
    return [
        'id' => 't_'.$customerId,
        'updated_time' => '2026-09-16T04:20:20+0000',
        'participants' => ['data' => [
            ['name' => $customerName, 'id' => $customerId],
            ['name' => 'Golden Info Systems Ltd.', 'id' => '106069340959538'],
        ]],
        // Meta returns newest first.
        'messages' => ['data' => array_reverse($messages)],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $conversations
 */
function importFake(array $conversations): void
{
    Http::fake([
        'graph.facebook.com/*/conversations*' => Http::response(['data' => $conversations]),
    ]);
}

beforeEach(function () {
    app(SettingsManager::class)->set('meta.app_id', '1772978827247269');
    app(SettingsManager::class)->set('meta.app_secret', 'app-secret-value');
});

test('a page\'s conversations arrive as ordinary threads', function () {
    $page = importPage();

    importFake([importConversation('28433708362979354', 'Ibrahim Khalil', [
        ['id' => 'm_first', 'message' => 'Do you deliver to Chattogram?', 'from' => ['id' => '28433708362979354'], 'created_time' => '2026-09-10T09:00:00+0000'],
        ['id' => 'm_reply', 'message' => 'We do, next day.', 'from' => ['id' => '106069340959538'], 'created_time' => '2026-09-10T09:05:00+0000'],
    ])]);

    $counts = app(ImportMessengerHistoryAction::class)($page);

    expect($counts)->toBe(['conversations' => 1, 'messages' => 2, 'skipped' => 0]);

    $conversation = SocialConversation::query()->firstOrFail();

    expect($conversation->participant_name)->toBe('Ibrahim Khalil')
        // Threaded on the customer's id — what a live delivery threads on —
        // rather than Meta's own `t_` conversation id, which would make a
        // second thread the moment they wrote again.
        ->and($conversation->external_conversation_id)->toBe('28433708362979354')
        ->and($conversation->channel_account_id)->toBe('106069340959538');

    $messages = $conversation->messages;

    // Oldest first, so the thread reads in the order it happened.
    expect($messages->first()->body)->toBe('Do you deliver to Chattogram?')
        ->and($messages->first()->direction)->toBe(MessageDirection::Inbound->value)
        ->and($messages->last()->direction)->toBe(MessageDirection::Outbound->value);
});

test('the reply window comes from the customer, not from the import', function () {
    Carbon::setTestNow('2026-09-16 12:00:00');

    $page = importPage();

    importFake([importConversation('PSID-1', 'Dara Okafor', [
        ['id' => 'm_old', 'message' => 'Are you open Saturday?', 'from' => ['id' => 'PSID-1'], 'created_time' => '2026-08-20T09:00:00+0000'],
    ])]);

    app(ImportMessengerHistoryAction::class)($page);

    $conversation = SocialConversation::query()->firstOrFail();

    // Seven days from *their* message, which is weeks ago — so this thread is
    // correctly outside its window. Dating it from the import would tell an
    // agent they may reply freely when Meta will refuse.
    expect($conversation->window_expires_at?->toDateString())->toBe('2026-08-27')
        ->and($conversation->isWindowOpen())->toBeFalse();

    Carbon::setTestNow();
});

test('importing twice imports nothing twice', function () {
    $page = importPage();

    importFake([importConversation('PSID-2', 'Rafiq Hasan', [
        ['id' => 'm_once', 'message' => 'Hello', 'from' => ['id' => 'PSID-2'], 'created_time' => '2026-09-14T09:00:00+0000'],
    ])]);

    app(ImportMessengerHistoryAction::class)($page);
    $second = app(ImportMessengerHistoryAction::class)($page);

    // Idempotent on Meta's own message id, exactly as a replayed webhook is.
    expect($second['messages'])->toBe(0)
        ->and(SocialMessage::query()->count())->toBe(1)
        ->and(SocialConversation::query()->count())->toBe(1);
});

test('an import creates no leads unless somebody asks for them', function () {
    $page = importPage();

    importFake([
        importConversation('PSID-3', 'One', [['id' => 'm_a', 'message' => 'Hi', 'from' => ['id' => 'PSID-3'], 'created_time' => '2026-09-14T09:00:00+0000']]),
        importConversation('PSID-4', 'Two', [['id' => 'm_b', 'message' => 'Hi', 'from' => ['id' => 'PSID-4'], 'created_time' => '2026-09-14T09:00:00+0000']]),
    ]);

    app(ImportMessengerHistoryAction::class)($page);

    // A stranger writing today is an enquiry worth a lead; two years of history
    // is not two years of new enquiries, and filling somebody's pipeline with
    // them on the press of a button is harder to undo than to skip.
    expect(Lead::query()->count())->toBe(0)
        ->and(SocialConversation::query()->count())->toBe(2);
});

test('leads are created when they are asked for', function () {
    $page = importPage();
    $owner = User::factory()->create();

    importFake([importConversation('PSID-5', 'Ibrahim Khalil', [
        ['id' => 'm_c', 'message' => 'Hi', 'from' => ['id' => 'PSID-5'], 'created_time' => '2026-09-14T09:00:00+0000'],
    ])]);

    app(ImportMessengerHistoryAction::class)($page, $owner);

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Ibrahim')
        ->and(SocialConversation::query()->value('lead_id'))->toBe($lead->id);
});

test('a conversation Meta returns with nothing readable leaves no empty thread', function () {
    $page = importPage();

    importFake([
        // Meta does return these: a deleted account, or a thread whose messages
        // are all outside what the edge will hand over.
        importConversation('PSID-6', 'Ghost', []),
    ]);

    $counts = app(ImportMessengerHistoryAction::class)($page);

    // An empty row in somebody's inbox reads as a fault.
    expect($counts['conversations'])->toBe(0)
        ->and(SocialConversation::query()->count())->toBe(0);
});

test('a conversation with nobody but the page in it is skipped', function () {
    $page = importPage();

    importFake([[
        'id' => 't_alone',
        'participants' => ['data' => [['name' => 'Golden Info Systems Ltd.', 'id' => '106069340959538']]],
        'messages' => ['data' => []],
    ]]);

    $counts = app(ImportMessengerHistoryAction::class)($page);

    expect($counts['skipped'])->toBe(1)
        ->and(SocialConversation::query()->count())->toBe(0);
});

test('a page with no stored token refuses in words', function () {
    $page = importPage();
    $page->forceFill(['access_token' => null])->save();

    Http::fake();

    expect(fn () => app(ImportMessengerHistoryAction::class)($page->fresh()))
        ->toThrow(MetaApiException::class);

    Http::assertNothingSent();
});

test('an imported thread and a live delivery are one conversation', function () {
    $page = importPage();

    importFake([importConversation('PSID-7', 'Dara Okafor', [
        ['id' => 'm_history', 'message' => 'Earlier question', 'from' => ['id' => 'PSID-7'], 'created_time' => '2026-09-14T09:00:00+0000'],
    ])]);

    app(ImportMessengerHistoryAction::class)($page);

    // The same customer writes again, this time through the webhook.
    $payload = [
        'object' => 'page',
        'entry' => [[
            'id' => '106069340959538',
            'messaging' => [[
                'sender' => ['id' => 'PSID-7'],
                'recipient' => ['id' => '106069340959538'],
                'timestamp' => Carbon::parse('2026-09-16 10:00:00')->getTimestampMs(),
                'message' => ['mid' => 'm_live', 'text' => 'Any update?'],
            ]],
        ]],
    ];

    $body = (string) json_encode($payload);

    test()->call('POST', route('api.webhooks.meta', ['channel' => 'messenger']), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret-value'),
    ], $body)->assertOk();

    // One thread, two messages — which is the whole reason the import threads
    // on the id a webhook threads on.
    expect(SocialConversation::query()->count())->toBe(1)
        ->and(SocialMessage::query()->count())->toBe(2);
});
