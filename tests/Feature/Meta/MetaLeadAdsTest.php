<?php

use App\Domain\Ingestion\Actions\ReplayIntegrationEventAction;
use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceMapping;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Enums\MetaLeadStatus;
use App\Domain\Meta\Leads\Actions\BackfillMetaLeadsAction;
use App\Domain\Meta\Models\MetaForm;
use App\Domain\Meta\Models\MetaLead;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Settings\SettingsManager;
use App\Jobs\BackfillMetaLeads;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Task 12.6 — a Facebook lead form becoming somebody to ring.
 */
beforeEach(function () {
    app(SettingsManager::class)->set('meta.app_secret', 'the-app-secret');
    app(SettingsManager::class)->set('meta.verify_token', 'the-verify-token');
});

/**
 * A connected page, with a token of its own to read leads with.
 */
function metaAdsPage(array $attributes = []): MetaPage
{
    return MetaPage::factory()->create(['page_id' => '1019283746', ...$attributes]);
}

/**
 * One lead, as Graph answers for it.
 */
function metaAdsGraphLead(array $overrides = []): array
{
    return [
        'id' => '900112233',
        'created_time' => '2026-09-14T09:12:00+0000',
        'form_id' => '5566778899',
        'campaign_id' => '23848',
        'campaign_name' => 'Spring offer',
        'adset_id' => '23849',
        'adset_name' => 'Bristol prospecting',
        'ad_id' => '23850',
        'ad_name' => 'Carousel A',
        'platform' => 'fb',
        'is_organic' => false,
        'field_data' => [
            ['name' => 'full_name', 'values' => ['Dara Okafor']],
            ['name' => 'email', 'values' => ['Dara@Example.com']],
            ['name' => 'phone_number', 'values' => ['+44 117 000 0000']],
            ['name' => 'company_name', 'values' => ['Okafor Plant Hire']],
        ],
        ...$overrides,
    ];
}

/**
 * Meta answering for the form and the lead, and nothing else.
 */
function metaAdsFakeGraph(?array $lead = null, array $before = []): void
{
    $lead ??= metaAdsGraphLead();

    Http::fake([
        ...$before,
        // The trailing `?` matters: without it this pattern also matches
        // `…/5566778899/leads`, and the backfill would be answered with the
        // form instead of the leads on it.
        'graph.facebook.com/*/5566778899?*' => Http::response([
            'id' => '5566778899',
            'name' => 'Spring offer enquiry',
            'status' => 'ACTIVE',
            'questions' => [
                ['key' => 'full_name', 'label' => 'Full name'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'phone_number', 'label' => 'Phone number'],
            ],
        ]),
        'graph.facebook.com/*/'.$lead['id'].'?*' => Http::response($lead),
    ]);
}

/**
 * The webhook Meta sends when somebody submits a form.
 */
function metaAdsDeliver(string $leadId = '900112233', string $pageId = '1019283746'): TestResponse
{
    $body = (string) json_encode([
        'object' => 'page',
        'entry' => [[
            'id' => $pageId,
            'time' => 1757840000,
            'changes' => [[
                'field' => 'leadgen',
                'value' => [
                    'leadgen_id' => $leadId,
                    'page_id' => $pageId,
                    'form_id' => '5566778899',
                    'created_time' => 1757840000,
                ],
            ]],
        ]],
    ]);

    return test()->call(
        'POST',
        route('api.webhooks.meta', ['channel' => 'leadgen']),
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'the-app-secret'),
        ],
        $body
    );
}

function metaAdsSource(array $attributes = []): DataSource
{
    $source = MetaSources::for(MetaChannel::LeadGen);

    if ($attributes !== []) {
        $source->forceFill($attributes)->save();
    }

    return $source->refresh();
}

// -- A submission becomes a lead -----------------------------------------------

test('a form submission becomes a lead with its answers and its attribution', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Dara')
        ->and($lead->last_name)->toBe('Okafor')
        ->and($lead->email)->toBe('Dara@Example.com')
        ->and($lead->phone)->toBe('+44 117 000 0000')
        ->and($lead->company_name)->toBe('Okafor Plant Hire')
        // A status is something a lead earns by being worked, never something a
        // payload asserts.
        ->and($lead->status)->toBe(LeadStatus::New->value)
        ->and($lead->source)->toBe(LeadSource::FacebookLeadAds->value);

    $attribution = $lead->attribution();

    expect($attribution->metaLeadId)->toBe('900112233')
        ->and($attribution->formName)->toBe('Spring offer enquiry')
        ->and($attribution->metaCampaignId)->toBe('23848')
        ->and($attribution->metaCampaignName)->toBe('Spring offer')
        ->and($attribution->metaAdSetName)->toBe('Bristol prospecting')
        ->and($attribution->metaAdName)->toBe('Carousel A')
        ->and($attribution->pageId)->toBe('1019283746')
        // Meta's moment, not ours: a lead retrieved late is still a lead from
        // the day the customer filled the form in.
        ->and($attribution->capturedAt?->toDateString())->toBe('2026-09-14');
});

test('the submission is recorded beside the lead it became', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    $submission = MetaLead::query()->firstOrFail();

    expect($submission->meta_lead_id)->toBe('900112233')
        ->and($submission->status())->toBe(MetaLeadStatus::Processed)
        ->and($submission->lead_id)->toBe(Lead::query()->value('id'))
        ->and($submission->meta_campaign_id)->toBe('23848')
        // The answers themselves, kept: a mapping corrected next week is
        // replayed against these.
        ->and($submission->payload)->toContain('Okafor Plant Hire');

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Processed)
        ->and($event->outcome)->toBe('created')
        ->and($event->record_id)->toBe($submission->lead_id);
});

test('the form is remembered, so attribution can name it rather than number it', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    $form = MetaForm::query()->firstOrFail();

    expect($form->form_id)->toBe('5566778899')
        ->and($form->name)->toBe('Spring offer enquiry')
        ->and($form->page_id)->toBe('1019283746')
        ->and($form->questionNames())->toContain('full_name')
        // Kept up to date by the webhook, which is what makes a later backfill
        // ask about the gap rather than the week.
        ->and($form->last_lead_at)->not->toBeNull();
});

test('a form read once is not read again for the next lead', function () {
    metaAdsPage();
    metaAdsFakeGraph(null, [
        'graph.facebook.com/*/900112299?*' => Http::response(metaAdsGraphLead([
            'id' => '900112299',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Ife Bello']],
                ['name' => 'email', 'values' => ['ife@example.com']],
            ],
        ])),
    ]);

    metaAdsDeliver('900112233')->assertOk();
    metaAdsDeliver('900112299')->assertOk();

    // Two leads, one description of the form. A Graph call per lead would spend
    // the rate limit re-reading a name that has not changed since breakfast.
    expect(Lead::query()->count())->toBe(2)
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), '/5566778899?'))->count())->toBe(1);
});

// -- One submission, one lead ---------------------------------------------------

test('the same meta lead id twice creates one lead', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    // A second delivery of the same submission. The door refuses the repeat,
    // and the submission's own row would refuse it again behind that.
    metaAdsDeliver()->assertOk()->assertJsonPath('status', 'duplicate');

    expect(Lead::query()->count())->toBe(1)
        ->and(MetaLead::query()->count())->toBe(1)
        ->and(IntegrationEvent::query()->count())->toBe(1);
});

test('replaying a processed delivery returns the lead it made rather than making another', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    $lead = Lead::query()->firstOrFail();
    $event = IntegrationEvent::query()->firstOrFail();

    app(ReplayIntegrationEventAction::class)($event);

    expect(Lead::query()->count())->toBe(1)
        ->and($event->refresh()->outcome)->toBe('duplicate')
        ->and($event->record_id)->toBe($lead->id);
});

// -- The duplicate policies -----------------------------------------------------

test('an existing lead on the same address is updated when that is the policy', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    $existing = Lead::factory()->create([
        'email' => 'dara@example.com',
        'first_name' => 'D',
        'last_name' => 'Okafor',
        'company_name' => null,
    ]);

    metaAdsSource(['dedupe_action' => DedupeAction::Update->value]);

    metaAdsDeliver()->assertOk();

    expect(Lead::query()->count())->toBe(1)
        ->and($existing->refresh()->first_name)->toBe('Dara')
        ->and($existing->company_name)->toBe('Okafor Plant Hire')
        // A later touch fills the gaps and leaves the first one alone.
        ->and($existing->attribution()->metaLeadId)->toBe('900112233');

    expect(IntegrationEvent::query()->value('outcome'))->toBe('updated');
});

test('a matched lead is left alone when the policy says skip, and still linked', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    $existing = Lead::factory()->create(['email' => 'dara@example.com', 'first_name' => 'D']);

    metaAdsSource(['dedupe_action' => DedupeAction::Skip->value]);

    metaAdsDeliver()->assertOk();

    expect(Lead::query()->count())->toBe(1)
        ->and($existing->refresh()->first_name)->toBe('D')
        // Linked anyway: the submission belongs to that person whether or not
        // it changed anything about them.
        ->and(MetaLead::query()->value('lead_id'))->toBe($existing->id)
        ->and(MetaLead::query()->firstOrFail()->status())->toBe(MetaLeadStatus::Skipped);

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->outcome)->toBe('skipped');
});

test('a source set to create makes a second lead, because a submission is an event', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    Lead::factory()->create(['email' => 'dara@example.com']);

    metaAdsSource(['dedupe_action' => DedupeAction::Create->value]);

    metaAdsDeliver()->assertOk();

    expect(Lead::query()->count())->toBe(2)
        ->and(IntegrationEvent::query()->value('outcome'))->toBe('created');
});

test('a telephone number written differently still matches', function () {
    metaAdsPage();
    metaAdsFakeGraph(metaAdsGraphLead([
        'field_data' => [
            ['name' => 'full_name', 'values' => ['Dara Okafor']],
            ['name' => 'phone_number', 'values' => ['+44 117 000 0000']],
        ],
    ]));

    // The same line, as somebody here typed it. A `where phone = ?` would miss
    // this and create the second copy dedupe exists to prevent.
    $existing = Lead::factory()->create([
        'email' => null,
        'phone' => '0117 000 0000',
        'mobile' => null,
    ]);

    metaAdsDeliver()->assertOk();

    expect(Lead::query()->count())->toBe(1)
        ->and(MetaLead::query()->value('lead_id'))->toBe($existing->id);
});

test('a lead merged away is not the one an update lands on', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    $survivor = Lead::factory()->create(['email' => 'dara@example.com']);
    $loser = Lead::factory()->create(['email' => 'dara@example.com']);

    $loser->forceFill(['merged_into_id' => $survivor->id, 'merged_at' => now()])->save();

    metaAdsDeliver()->assertOk();

    expect(MetaLead::query()->value('lead_id'))->toBe($survivor->id);
});

// -- What a payload may not decide ----------------------------------------------

test('the owner comes from the rule, not from the payload', function () {
    $owner = User::factory()->create();

    metaAdsPage();
    metaAdsSource(['default_owner_id' => $owner->id]);

    metaAdsFakeGraph(metaAdsGraphLead([
        'field_data' => [
            ['name' => 'full_name', 'values' => ['Dara Okafor']],
            ['name' => 'email', 'values' => ['dara@example.com']],
            // A form can ask anything, including questions named after our own
            // columns. None of them may choose who the lead belongs to.
            ['name' => 'owner_id', 'values' => ['999999']],
            ['name' => 'status', 'values' => ['converted']],
            ['name' => 'source', 'values' => ['referral']],
        ],
    ]));

    metaAdsDeliver()->assertOk();

    $lead = Lead::query()->firstOrFail();

    expect(leadOwnerId($lead))->toBe($owner->id)
        ->and($lead->status)->toBe(LeadStatus::New->value)
        ->and($lead->source)->toBe(LeadSource::FacebookLeadAds->value);
});

test('with no owner configured the lead belongs to whoever connected Meta', function () {
    $page = metaAdsPage();
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    expect(leadOwnerId(Lead::query()->firstOrFail()))->toBe($page->account->connected_by_id);
});

// -- When it cannot be done -----------------------------------------------------

test('a form that supplies no name fails the delivery rather than writing half a lead', function () {
    metaAdsPage();
    metaAdsFakeGraph(metaAdsGraphLead([
        'field_data' => [['name' => 'email', 'values' => ['dara@example.com']]],
    ]));

    metaAdsDeliver()->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        // Named, so somebody can go and look at the form rather than guess.
        ->and($event->error)->toContain('Spring offer enquiry')
        ->and($event->error)->toContain('first_name')
        ->and(Lead::query()->count())->toBe(0);

    $submission = MetaLead::query()->firstOrFail();

    expect($submission->status())->toBe(MetaLeadStatus::Failed)
        ->and($submission->lead_id)->toBeNull()
        // The answers are kept, which is what makes a replay worth having once
        // the mapping is fixed.
        ->and($submission->payload)->toContain('dara@example.com');
});

test('an answer the module would refuse fails the delivery', function () {
    metaAdsPage();
    metaAdsFakeGraph(metaAdsGraphLead([
        'field_data' => [
            ['name' => 'full_name', 'values' => ['Dara Okafor']],
            ['name' => 'email', 'values' => ['not-an-address']],
        ],
    ]));

    metaAdsDeliver()->assertOk();

    expect(IntegrationEvent::query()->firstOrFail()->status())->toBe(IntegrationEventStatus::Failed)
        ->and(Lead::query()->count())->toBe(0);
});

test('a page this installation does not manage is skipped, not failed', function () {
    // No page row at all. A business often has pages the CRM was never
    // connected to, and turning those into red rows would bury the deliveries
    // that are genuinely broken.
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->outcome)->toBe('unknown_page')
        ->and(Lead::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('a page that was never finished connecting says so', function () {
    MetaPage::factory()->unconnected()->create(['page_id' => '1019283746']);
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    expect(IntegrationEvent::query()->value('outcome'))->toBe('no_page_token');
});

test('Meta being unreachable leaves a retryable delivery, not a lost lead', function () {
    metaAdsPage();

    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Rate limited', 'code' => 4]], 429)]);

    metaAdsDeliver()->assertOk();

    $event = IntegrationEvent::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        // The body is kept, which is what 8.9's replay runs again.
        ->and($event->payload)->toContain('900112233')
        ->and(Lead::query()->count())->toBe(0);
});

// -- The mapping ----------------------------------------------------------------

test('a form asking its questions separately maps without anybody configuring it', function () {
    metaAdsPage();
    metaAdsFakeGraph(metaAdsGraphLead([
        'field_data' => [
            ['name' => 'first_name', 'values' => ['Maria del Carmen']],
            ['name' => 'last_name', 'values' => ['Okafor']],
            ['name' => 'work_email', 'values' => ['maria@example.com']],
            ['name' => 'job_title', 'values' => ['Operations Director']],
            ['name' => 'city', 'values' => ['Bristol']],
            ['name' => 'post_code', 'values' => ['BS1 4DJ']],
        ],
    ]));

    metaAdsDeliver()->assertOk();

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Maria del Carmen')
        ->and($lead->last_name)->toBe('Okafor')
        ->and($lead->email)->toBe('maria@example.com')
        ->and($lead->job_title)->toBe('Operations Director')
        ->and($lead->city)->toBe('Bristol')
        ->and($lead->postal_code)->toBe('BS1 4DJ');
});

test('an administrator\'s own mapping replaces the defaults entirely', function () {
    metaAdsPage();

    $source = metaAdsSource();

    // A form with a custom question, which no default could know about.
    DataSourceMapping::query()->create([
        'data_source_id' => $source->id,
        'source_path' => 'field_data.what_is_your_name',
        'target_field' => 'first_name',
        'transform' => 'name_first',
    ]);
    DataSourceMapping::query()->create([
        'data_source_id' => $source->id,
        'source_path' => 'field_data.what_is_your_name',
        'target_field' => 'last_name',
        'transform' => 'name_last',
    ]);
    DataSourceMapping::query()->create([
        'data_source_id' => $source->id,
        'source_path' => 'field_data.how_do_we_reach_you',
        'target_field' => 'email',
    ]);

    metaAdsFakeGraph(metaAdsGraphLead([
        'field_data' => [
            ['name' => 'what_is_your_name', 'values' => ['Dara Okafor']],
            ['name' => 'how_do_we_reach_you', 'values' => ['dara@example.com']],
            // Mapped by a default, and deliberately not written: the moment
            // somebody saves a mapping, theirs is the whole mapping.
            ['name' => 'company_name', 'values' => ['Okafor Plant Hire']],
        ],
    ]));

    metaAdsDeliver()->assertOk();

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Dara')
        ->and($lead->email)->toBe('dara@example.com')
        ->and($lead->company_name)->toBeNull();
});

test('a checkbox answer takes the first value rather than joining them into a column', function () {
    metaAdsPage();
    metaAdsFakeGraph(metaAdsGraphLead([
        'field_data' => [
            ['name' => 'full_name', 'values' => ['Dara Okafor']],
            ['name' => 'email', 'values' => ['dara@example.com', 'second@example.com']],
        ],
    ]));

    metaAdsDeliver()->assertOk();

    expect(Lead::query()->value('email'))->toBe('dara@example.com');
});

// -- Telling somebody ------------------------------------------------------------

test('the owner is told a lead is waiting', function () {
    $owner = User::factory()->create();

    metaAdsPage();
    metaAdsSource(['default_owner_id' => $owner->id]);
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    expect(NotificationLog::query()
        ->where('event', 'meta.lead_received')
        ->where('user_id', $owner->id)
        ->exists())->toBeTrue();
});

test('an update does not announce itself as a new lead', function () {
    metaAdsPage();
    metaAdsFakeGraph();

    Lead::factory()->create(['email' => 'dara@example.com']);

    metaAdsDeliver()->assertOk();

    // Somebody already had this person. A second notification saying a new lead
    // arrived would be a lie about what happened.
    expect(NotificationLog::query()->where('event', 'meta.lead_received')->exists())->toBeFalse();
});

// -- The backfill ----------------------------------------------------------------

test('the backfill fetches what the webhook missed and puts it through the same pipeline', function () {
    $page = metaAdsPage();

    Http::fake([
        'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => [[
            'id' => '5566778899',
            'name' => 'Spring offer enquiry',
            'status' => 'ACTIVE',
            'questions' => [['key' => 'full_name', 'label' => 'Full name']],
        ]]]),
        'graph.facebook.com/*/leads*' => Http::response(['data' => [metaAdsGraphLead()]]),
    ]);

    $counts = app(BackfillMetaLeadsAction::class)($page);

    expect($counts['forms'])->toBe(1)
        ->and($counts['found'])->toBe(1)
        ->and($counts['queued'])->toBe(1);

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Dara')
        ->and($lead->attribution()->metaCampaignName)->toBe('Spring offer')
        // The same log, the same replay, the same health panel as a pushed
        // delivery — and honestly marked as something nobody signed.
        ->and(IntegrationEvent::query()->firstOrFail()->signature_verified)->toBeFalse()
        ->and(IntegrationEvent::query()->value('external_id'))->toBe('leadgen:900112233');
});

test('a backfilled lead is not fetched again one at a time', function () {
    $page = metaAdsPage();

    Http::fake([
        'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => [[
            'id' => '5566778899', 'name' => 'Spring offer enquiry', 'status' => 'ACTIVE', 'questions' => [],
        ]]]),
        'graph.facebook.com/*/leads*' => Http::response(['data' => [metaAdsGraphLead()]]),
    ]);

    app(BackfillMetaLeadsAction::class)($page);

    // It arrived complete. Asking Meta again for each one would spend a call per
    // lead against the very rate limit a backfill is catching up through.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/900112233'));
});

test('the backfill leaves alone what has already arrived', function () {
    $page = metaAdsPage();
    metaAdsFakeGraph();

    metaAdsDeliver()->assertOk();

    Http::fake([
        'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => [[
            'id' => '5566778899', 'name' => 'Spring offer enquiry', 'status' => 'ACTIVE', 'questions' => [],
        ]]]),
        'graph.facebook.com/*/leads*' => Http::response(['data' => [metaAdsGraphLead()]]),
    ]);

    $counts = app(BackfillMetaLeadsAction::class)($page);

    expect($counts['found'])->toBe(1)
        ->and($counts['queued'])->toBe(0)
        ->and(Lead::query()->count())->toBe(1)
        ->and(IntegrationEvent::query()->count())->toBe(1);
});

test('a page nobody finished connecting is not backfilled', function () {
    $page = MetaPage::factory()->unconnected()->create();

    Http::fake();

    $counts = app(BackfillMetaLeadsAction::class)($page);

    expect($counts['forms'])->toBe(0);

    Http::assertNothingSent();
});

test('the command queues a backfill for every connected page', function () {
    metaAdsPage();
    MetaPage::factory()->unconnected()->create(['page_id' => '2029384756']);

    Queue::fake();

    $this->artisan('meta:backfill-leads')->assertSuccessful();

    Queue::assertPushed(BackfillMetaLeads::class, 1);
});
