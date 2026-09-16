<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Attribution\Models\RecordAttribution;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaAd;
use App\Domain\Meta\Models\MetaAdSet;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Settings\SettingsManager;
use App\Domain\Social\Models\SocialConversation;
use App\Livewire\Social\SocialInbox;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Task 12.11 — click-to-message advertisements.
 *
 * A customer who taps "Send message" on an advertisement arrives as an ordinary
 * message with a `referral` object attached. That object is the **only** time
 * the advertisement is ever named: it is not on their second message and cannot
 * be asked for afterwards, so everything here is about reading it once and
 * keeping it.
 */
beforeEach(function () {
    app(SettingsManager::class)->set('meta.app_secret', 'the-app-secret');
});

function ctwaNumber(): WhatsAppPhoneNumber
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
        'is_default' => true,
    ]);
}

/**
 * The advertisement, as 12.7's sync would have stored it.
 */
function ctwaAdvertisement(): MetaAd
{
    MetaCampaign::query()->create([
        'meta_campaign_id' => '120200000000001',
        'ad_account_id' => '23914816791552795',
        'name' => 'Monsoon plant hire',
    ]);

    MetaAdSet::query()->create([
        'meta_ad_set_id' => '120200000000002',
        'meta_campaign_id' => '120200000000001',
        'name' => 'Dhaka — contractors',
    ]);

    return MetaAd::query()->create([
        'meta_ad_id' => '120200000000003',
        'meta_ad_set_id' => '120200000000002',
        'meta_campaign_id' => '120200000000001',
        'name' => 'Digger — carousel',
    ]);
}

/**
 * Meta's own referral shape for WhatsApp: the ad is `source_id`, and the click
 * is `ctwa_clid`.
 */
function ctwaReferral(array $overrides = []): array
{
    return [
        'source_url' => 'https://fb.me/2xKq9',
        'source_id' => '120200000000003',
        'source_type' => 'ad',
        'headline' => 'Diggers from ৳4,500/day',
        'body' => 'Same-day delivery across Dhaka.',
        'media_type' => 'image',
        'ctwa_clid' => 'ARAkQ8t9click',
        ...$overrides,
    ];
}

function ctwaDeliver(array $message): TestResponse
{
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '99887766',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '441170000000', 'phone_number_id' => '556677889900'],
                    'contacts' => [['profile' => ['name' => 'Dara Okafor'], 'wa_id' => '447700900123']],
                    'messages' => [[
                        'from' => '447700900123',
                        'id' => 'wamid.CTWA1',
                        'timestamp' => (string) Carbon::parse('2026-09-15 09:00:00')->getTimestamp(),
                        'type' => 'text',
                        'text' => ['body' => 'Is the 3-tonne digger available Saturday?'],
                        ...$message,
                    ]],
                ],
            ]],
        ]],
    ];

    $body = (string) json_encode($payload);

    return test()->call('POST', route('api.webhooks.meta', ['channel' => 'whatsapp']), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-app-secret'),
    ], $body);
}

// -- Attribution ---------------------------------------------------------------

test('a referral attributes the lead to the campaign that paid for it', function () {
    ctwaNumber();
    ctwaAdvertisement();

    ctwaDeliver(['referral' => ctwaReferral()])->assertOk();

    $lead = Lead::query()->firstOrFail();
    $attribution = $lead->attribution();

    expect($attribution->metaAdId)->toBe('120200000000003')
        // Resolved through 12.7's tables: the referral names an id, and every
        // name a person reads comes from the sync.
        ->and($attribution->metaAdName)->toBe('Digger — carousel')
        ->and($attribution->metaAdSetId)->toBe('120200000000002')
        ->and($attribution->metaAdSetName)->toBe('Dhaka — contractors')
        ->and($attribution->metaCampaignId)->toBe('120200000000001')
        ->and($attribution->metaCampaignName)->toBe('Monsoon plant hire')
        // The click, which is what 12.12 sends back when this lead is won.
        ->and($attribution->clickId)->toBe('ARAkQ8t9click')
        ->and($attribution->isFromMeta())->toBeTrue();
});

test('an advertisement the CRM has not synced still attributes what it knows', function () {
    ctwaNumber();

    // No MetaAd row: the advertisement was created this morning, or its ad
    // account was never connected.
    ctwaDeliver(['referral' => ctwaReferral()])->assertOk();

    $attribution = Lead::query()->firstOrFail()->attribution();

    expect($attribution->metaAdId)->toBe('120200000000003')
        ->and($attribution->metaAdName)->toBeNull()
        ->and($attribution->metaCampaignId)->toBeNull()
        // The headline the customer actually saw, which is what an agent can
        // use while the ids mean nothing to them.
        ->and($attribution->sourceDetail)->toBe('Diggers from ৳4,500/day')
        ->and($attribution->clickId)->toBe('ARAkQ8t9click');
});

test('a message with no referral is attributed to the channel and nothing more', function () {
    ctwaNumber();

    ctwaDeliver([])->assertOk();

    $attribution = Lead::query()->firstOrFail()->attribution();

    // Unattributed rather than wrongly attributed: an organic enquiry credited
    // to whichever campaign was running would overstate that campaign and
    // understate everything else.
    expect($attribution->source)->toBe('whatsapp')
        ->and($attribution->metaAdId)->toBeNull()
        ->and($attribution->metaCampaignId)->toBeNull()
        ->and($attribution->clickId)->toBeNull();
});

test('the referral is kept on the conversation, so a lead made by hand is attributed too', function () {
    ctwaNumber();
    ctwaAdvertisement();

    // Somebody the CRM already knows: no lead is created, so the referral has
    // nowhere to go unless the conversation keeps it.
    Contact::factory()->create(['mobile' => '447700900123']);

    ctwaDeliver(['referral' => ctwaReferral()])->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();

    expect($conversation->referral()?->adId)->toBe('120200000000003')
        ->and($conversation->referral()?->clickId)->toBe('ARAkQ8t9click');
});

test('a customer the CRM already knows has their attribution filled in, never overwritten', function () {
    ctwaNumber();
    ctwaAdvertisement();

    $contact = Contact::factory()->create(['mobile' => '447700900123']);

    // They came from a trade show two years ago. That is still where they came
    // from; today's advertisement is not a reason to rewrite it.
    $contact->recordAttribution(new MarketingAttribution(
        source: 'event',
        sourceDetail: 'Dhaka Construction Expo',
        capturedAt: Carbon::parse('2024-03-02'),
    ));

    ctwaDeliver(['referral' => ctwaReferral()])->assertOk();

    $attribution = $contact->fresh()->attribution();

    expect($attribution->source)->toBe('event')
        ->and($attribution->sourceDetail)->toBe('Dhaka Construction Expo')
        // The gaps are filled, which is how the advertisement still gets credit
        // for the conversation it started.
        ->and($attribution->metaAdId)->toBe('120200000000003')
        ->and($attribution->capturedAt?->toDateString())->toBe('2024-03-02');
});

test('a second advertisement does not rewrite the first', function () {
    ctwaNumber();

    ctwaDeliver(['referral' => ctwaReferral()])->assertOk();

    ctwaDeliver([
        'id' => 'wamid.CTWA2',
        'referral' => ctwaReferral(['source_id' => '999888777', 'ctwa_clid' => 'ARAsecondclick']),
    ])->assertOk();

    $conversation = SocialConversation::query()->firstOrFail();

    // First touch: the advertisement that started the relationship is the one
    // that won it. A later click is a second visit, not a correction.
    expect($conversation->referral()?->adId)->toBe('120200000000003')
        ->and(Lead::query()->firstOrFail()->attribution()->metaAdId)->toBe('120200000000003')
        ->and(RecordAttribution::query()->count())->toBe(1);
});

test('a shortlink referral records the link without inventing a campaign', function () {
    ctwaNumber();

    // A `ref` and no advertisement: somebody put a wa.me link on a poster.
    ctwaDeliver(['referral' => ['ref' => 'autumn-poster', 'source_type' => 'shortlink']])->assertOk();

    $attribution = Lead::query()->firstOrFail()->attribution();

    expect($attribution->sourceDetail)->toBe('autumn-poster')
        ->and($attribution->metaAdId)->toBeNull()
        ->and($attribution->metaCampaignId)->toBeNull();
});

// -- Messenger -----------------------------------------------------------------

test('Messenger names the advertisement differently and is read all the same', function () {
    $account = MetaAccount::factory()->create();

    MetaPage::query()->create([
        'meta_account_id' => $account->id,
        'page_id' => '106069340959538',
        'name' => 'Golden Info Systems Ltd.',
        'access_token' => 'page-token',
        'is_subscribed' => true,
    ]);

    ctwaAdvertisement();

    $payload = [
        'object' => 'page',
        'entry' => [[
            'id' => '106069340959538',
            'messaging' => [[
                'sender' => ['id' => 'PSID-4411'],
                'recipient' => ['id' => '106069340959538'],
                'timestamp' => Carbon::parse('2026-09-15 09:00:00')->getTimestampMs(),
                'message' => ['mid' => 'm_ctwa_1', 'text' => 'Do you deliver to Chattogram?'],
                // `ad_id`, not `source_id`, and `source` rather than
                // `source_type` — the same fact under different names.
                'referral' => ['ad_id' => '120200000000003', 'source' => 'ADS', 'type' => 'OPEN_THREAD'],
            ]],
        ]],
    ];

    $body = (string) json_encode($payload);

    test()->call('POST', route('api.webhooks.meta', ['channel' => 'messenger']), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-app-secret'),
    ], $body)->assertOk();

    $attribution = Lead::query()->firstOrFail()->attribution();

    expect($attribution->metaAdId)->toBe('120200000000003')
        ->and($attribution->metaCampaignName)->toBe('Monsoon plant hire')
        ->and($attribution->source)->toBe('facebook_messenger');
});

// -- The inbox -----------------------------------------------------------------

test('the inbox says which advertisement started the conversation', function () {
    ctwaNumber();
    ctwaAdvertisement();

    ctwaDeliver(['referral' => ctwaReferral()])->assertOk();

    $agent = User::factory()->create();

    foreach (PermissionResolver::models(['social.inbox.view', 'leads.view']) as $model) {
        $agent->givePermissionTo($model);
    }

    Livewire\Livewire::actingAs($agent->fresh())
        ->test(SocialInbox::class)
        ->set('conversation', SocialConversation::query()->value('id'))
        ->assertSee('Diggers from ৳4,500/day')
        ->assertSee('Monsoon plant hire');
});
