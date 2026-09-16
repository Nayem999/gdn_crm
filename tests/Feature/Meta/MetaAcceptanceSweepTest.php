<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Ingestion\IntegrationEventFields;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Meta\Actions\ConnectMetaAccountAction;
use App\Domain\Meta\Actions\ConnectMetaWithTokenAction;
use App\Domain\Meta\Ads\LinkMetaCampaignAction;
use App\Domain\Meta\Ads\SyncMetaAdStructureAction;
use App\Domain\Meta\Ads\SyncMetaInsightsAction;
use App\Domain\Meta\Analytics\CampaignPerformance;
use App\Domain\Meta\Auth\MetaAuthService;
use App\Domain\Meta\Conversions\Enums\ConversionOutcome;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Leads\Actions\BackfillMetaLeadsAction;
use App\Domain\Meta\Leads\MetaLeadService;
use App\Domain\Meta\MetaConfiguration;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Webhooks\MetaEventProcessor;
use App\Domain\Meta\Webhooks\MetaSources;
use App\Domain\Reports\ReportSources;
use App\Domain\Reports\StandardReports;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Social\Actions\ImportMessengerHistoryAction;
use App\Domain\Social\Actions\SyncWhatsAppTemplatesAction;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Enums\TemplateStatus;
use App\Domain\Social\MessagingWindow;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Referrals\ClickToMessageReferral;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Task 12.14 — the phase's acceptance criteria, swept in one place.
 *
 * **What this is and is not.** The behaviour of each capability is tested where
 * it lives — threading in the inbox tests, idempotency in the conversions
 * tests, the arithmetic in the performance tests — and repeating those here
 * would be a second copy of the suite to keep in step with the first. What this
 * sweep asserts is that each capability is **wired in**: registered, reachable,
 * permissioned and visible, which is the failure a phase actually ships with.
 * A handler that works perfectly and was never registered passes its own tests
 * and does nothing in production.
 *
 * The criteria are taken from META_BUILD.md's task list, which is the plan this
 * phase was built against; the original brief's §43 is not in the repository,
 * so this is the honest equivalent rather than a transcription of it.
 */
function sweepUser(array $permissions): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

// -- 1-3: credentials ----------------------------------------------------------

test('1. the Meta app credentials live in settings, encrypted, never in the environment', function () {
    $group = SettingsRegistry::group('meta');

    $secrets = collect($group['fields'])->filter(fn ($field) => $field->secret)->pluck('key');

    expect($secrets)->toContain('app_secret')
        ->and($secrets)->toContain('verify_token');

    // The decision in META_BUILD §6: a credential in .env is a credential in
    // every deployment script and backup.
    expect(file_get_contents(dirname(__DIR__, 3).'/.env.example'))
        ->not->toContain('META_APP_SECRET');
});

test('2. the connection can be made by OAuth or by a pasted token', function () {
    expect(class_exists(ConnectMetaAccountAction::class))->toBeTrue()
        ->and(class_exists(ConnectMetaWithTokenAction::class))->toBeTrue();

    // Everything this integration needs, asked for together: Meta shows one
    // consent screen and a second trip is a second chance to decline.
    foreach (['leads_retrieval', 'pages_messaging', 'ads_read', 'whatsapp_business_messaging'] as $scope) {
        expect(MetaAuthService::SCOPES)->toContain($scope);
    }
});

test('3. the Graph version is validated rather than trusted', function () {
    settings()->set('meta.graph_version', 'latest');

    // "latest" is a URL Meta answers with an error that says nothing about the
    // real cause, so an unparseable version falls back to the packaged one.
    expect(app(MetaConfiguration::class)->version())->toStartWith('v');
});

// -- 4-7: the gateway ----------------------------------------------------------

test('4. every channel Meta delivers on has a webhook address', function () {
    foreach (MetaChannel::cases() as $channel) {
        $url = route('api.webhooks.meta', $channel->value);

        expect($url)->toContain('/api/webhooks/meta/'.$channel->value);
    }
});

test('5. each channel is answered by a registered handler', function () {
    // A handler that works perfectly and was never registered passes its own
    // tests and does nothing in production. This is that check.
    foreach (MetaChannel::cases() as $channel) {
        expect(MetaEventProcessor::handlerFor($channel))
            ->not->toBeNull("no handler registered for {$channel->value}");
    }
});

test('6. each channel owns a source in the delivery log, made on demand', function () {
    // The gateway is the one 12.5 reused rather than a second pipeline, so
    // every Meta delivery is visible in the delivery log beside every other
    // integration's — under a source of its own, so a lapsed Lead Ads
    // subscription cannot make the WhatsApp number look broken.
    foreach (MetaChannel::cases() as $channel) {
        $source = MetaSources::for($channel);

        expect($source->name)->toBe($channel->sourceName())
            ->and(MetaSources::channelFor($source))->toBe($channel);
    }

    expect(DataSource::query()->count())->toBe(count(MetaChannel::cases()));
});

test('7. the delivery log offers Meta among its sources', function () {
    DataSource::query()->create([
        'name' => MetaChannel::LeadGen->sourceName(),
        'target_module' => 'leads',
        'is_active' => true,
    ]);

    expect(IntegrationEventFields::sourceOptions())
        ->toContain(MetaChannel::LeadGen->sourceName());
});

// -- 8-12: leads, ads and money ------------------------------------------------

test('8. a Meta lead becomes a CRM lead through the shared duplicate engine', function () {
    expect(class_exists(MetaLeadService::class))->toBeTrue()
        ->and(class_exists(BackfillMetaLeadsAction::class))->toBeTrue();
});

test('9. the advertising structure and its figures are synced separately', function () {
    expect(class_exists(SyncMetaAdStructureAction::class))->toBeTrue()
        ->and(class_exists(SyncMetaInsightsAction::class))->toBeTrue()
        // Meta restates a day's figures for up to 72 hours.
        ->and(SyncMetaInsightsAction::RESTATEMENT_DAYS)->toBeGreaterThanOrEqual(3);
});

test('10. Meta spend never writes the CRM campaign\'s own cost', function () {
    $campaign = Campaign::factory()->create(['actual_cost' => 1000]);
    $meta = MetaCampaign::factory()->create();

    app(LinkMetaCampaignAction::class)
        ->link($meta, $campaign, sweepUser(['meta.campaigns.manage', 'campaigns.view']));

    // The column is a typed-in figure only a person writes: one an integration
    // could overwrite is one nobody can correct.
    expect($campaign->fresh()->actual_cost)->toEqual(1000);
});

test('11. the chain from spend to revenue is computed from stored rows', function () {
    $user = sweepUser(['meta.campaigns.view', 'leads.view', 'deals.view']);

    // No HTTP fake and no network: if this touched Meta the test would fail.
    $total = app(CampaignPerformance::class)->total($user, now()->subMonth(), now());

    expect($total->spend)->toBe(0.0)
        // A figure with no denominator has no answer.
        ->and($total->costPerLead())->toBeNull();
});

test('12. the advertising reports are installable and name real fields', function () {
    foreach (['leads-by-meta-campaign', 'revenue-by-meta-campaign', 'meta-conversion-funnel'] as $slug) {
        $report = StandardReports::find($slug);

        expect($report)->not->toBeNull("{$slug} is not declared");

        $source = ReportSources::find($report['definition']->source);

        foreach ($report['definition']->dimensions as $dimension) {
            expect($source->dimensions)->toHaveKey($dimension);
        }
    }
});

// -- 13-18: the inbox ----------------------------------------------------------

test('13. one inbox serves every channel, reachable per channel', function () {
    $agent = sweepUser(['social.inbox.view']);

    foreach (SocialChannel::cases() as $channel) {
        $this->actingAs($agent)
            ->get(route('social.inbox', ['channel' => $channel->value]))
            ->assertOk();
    }
});

test('14. each channel carries Meta\'s own reply window', function () {
    expect(SocialChannel::WhatsApp->replyWindowHours())->toBe(24)
        ->and(SocialChannel::Messenger->replyWindowHours())->toBe(24 * 7);
});

test('15. a closed window refuses in words and names the remedy', function () {
    $conversation = SocialConversation::factory()
        ->onChannel(SocialChannel::WhatsApp)
        ->create(['window_expires_at' => now()->subDay()]);

    $refusal = MessagingWindow::refusal($conversation->channel(), $conversation->window_expires_at);

    expect($refusal)->toContain('24-hour')
        ->and($refusal)->toContain('template');
});

test('16. templates are synced, and only an approved one may be sent', function () {
    expect(class_exists(SyncWhatsAppTemplatesAction::class))->toBeTrue()
        ->and(TemplateStatus::Approved->isSendable())->toBeTrue()
        ->and(TemplateStatus::Paused->isSendable())->toBeFalse();
});

test('17. a conversation can be started from a lead or a contact', function () {
    expect(fn () => route('social.start.lead', 1))->not->toThrow(Exception::class)
        ->and(fn () => route('social.start.contact', 1))->not->toThrow(Exception::class);
});

test('18. Messenger history can be imported; WhatsApp has none to import', function () {
    expect(class_exists(ImportMessengerHistoryAction::class))->toBeTrue();

    // Deliberate: the Cloud API publishes no endpoint for WhatsApp history, so
    // there is no action to find here.
    expect(class_exists('App\\Domain\\Social\\Actions\\ImportWhatsAppHistoryAction'))->toBeFalse();
});

// -- 19-22: attribution and conversions ----------------------------------------

test('19. a click-to-message advertisement attributes the conversation it starts', function () {
    expect(class_exists(ClickToMessageReferral::class))->toBeTrue();

    // Meta spells the same fact differently per channel.
    $whatsApp = ClickToMessageReferral::fromPayload(['source_id' => '123', 'ctwa_clid' => 'abc']);
    $messenger = ClickToMessageReferral::fromPayload(['ad_id' => '123']);

    expect($whatsApp?->adId)->toBe('123')
        ->and($messenger?->adId)->toBe('123');
});

test('20. the three CRM outcomes are reported back to Meta, and only those', function () {
    expect(ConversionOutcome::cases())->toHaveCount(3)
        ->and(ConversionOutcome::Won->eventName())->toBe('Purchase')
        // Only the win carries money.
        ->and(ConversionOutcome::Won->carriesValue())->toBeTrue()
        ->and(ConversionOutcome::Opportunity->carriesValue())->toBeFalse();
});

test('21. a conversion is reported once, enforced by the database', function () {
    $indexes = collect(Schema::getIndexes('meta_conversion_events'))
        ->filter(fn (array $index): bool => $index['unique'] === true)
        ->flatMap(fn (array $index): array => $index['columns']);

    expect($indexes)->toContain('event_id');
});

test('22. nothing is reported for a record Meta never sent', function () {
    $attribution = new MarketingAttribution(source: 'cold_call');

    expect($attribution->isFromMeta())->toBeFalse();
});

// -- 23-25: finding it, and who did what ---------------------------------------

test('23. every Meta screen is reachable and behind its own permission', function (string $route, string $permission) {
    $this->actingAs(sweepUser([]))->get(route($route))->assertForbidden();
    $this->actingAs(sweepUser([$permission]))->get(route($route))->assertOk();
})->with([
    ['settings.meta.connect', 'meta.view'],
    ['settings.meta.campaigns', 'meta.campaigns.view'],
    ['settings.meta.performance', 'meta.campaigns.view'],
    ['settings.meta.conversions', 'meta.view'],
    ['social.inbox', 'social.inbox.view'],
]);

test('24. the marketing section gathers them where somebody looks', function () {
    $marketer = sweepUser(['social.inbox.view', 'meta.campaigns.view', 'meta.view', 'campaigns.view']);

    $this->actingAs($marketer)
        ->get('/')
        ->assertSee('Marketing &amp; social', false)
        ->assertSee('WhatsApp chat')
        ->assertSee('Messenger chat')
        ->assertSee('Ad performance');
});

test('25. what a person decided about a conversation is audited; what a delivery changed is not', function () {
    $agent = sweepUser(['social.inbox.view', 'social.inbox.reply']);
    $conversation = SocialConversation::factory()->create();

    Activity::query()->delete();

    $conversation->forceFill(['assigned_to_id' => $agent->id])->save();
    expect(Activity::query()->count())->toBe(1);

    // A message arriving moves these on every delivery; logging them would bury
    // the entries somebody opened the trail to find.
    $conversation->forceFill(['unread_count' => 5, 'last_message_at' => now()])->save();
    expect(Activity::query()->count())->toBe(1);
});
