<?php

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Meta\Ads\LinkMetaCampaignAction;
use App\Domain\Meta\Ads\SyncMetaAdStructureAction;
use App\Domain\Meta\Ads\SyncMetaInsightsAction;
use App\Domain\Meta\Enums\MetaAdLevel;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Models\MetaAd;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaAdSet;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Models\MetaInsight;
use App\Jobs\SyncMetaAds;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Task 12.7 — reading what Meta spent, and tying it to what the CRM earned.
 */
function metaSyncAccount(array $attributes = []): MetaAdAccount
{
    return MetaAdAccount::factory()->create(['ad_account_id' => '556677', ...$attributes]);
}

/**
 * One campaign, one ad set and one ad, as Graph returns them.
 */
function metaSyncStructure(array $overrides = []): array
{
    return [
        'graph.facebook.com/*/act_556677/campaigns*' => Http::response(['data' => [[
            'id' => '23848',
            'name' => 'Spring offer',
            'objective' => 'OUTCOME_LEADS',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            // Minor units: five thousand pence is fifty pounds.
            'daily_budget' => '5000',
            'start_time' => '2026-09-01T00:00:00+0000',
        ]]]),
        'graph.facebook.com/*/act_556677/adsets*' => Http::response(['data' => [[
            'id' => '23849',
            'name' => 'Bristol prospecting',
            'campaign_id' => '23848',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'optimization_goal' => 'LEAD_GENERATION',
            'billing_event' => 'IMPRESSIONS',
            'daily_budget' => '2500',
        ]]]),
        'graph.facebook.com/*/act_556677/ads?*' => Http::response(['data' => [[
            'id' => '23850',
            'name' => 'Carousel A',
            'adset_id' => '23849',
            'campaign_id' => '23848',
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'creative' => ['name' => 'Spring carousel', 'title' => 'Plant hire', 'body' => 'Delivered to site.'],
        ]]]),
        ...$overrides,
    ];
}

/**
 * A day of figures, which Meta returns carrying every id that applies at the
 * level it was asked for.
 */
function metaSyncInsightRow(string $date = '2026-09-14', string $spend = '42.50'): array
{
    return [
        'campaign_id' => '23848',
        'adset_id' => '23849',
        'ad_id' => '23850',
        'spend' => $spend,
        'impressions' => '12000',
        'reach' => '9000',
        'clicks' => '240',
        'ctr' => '2.0000',
        'cpc' => '0.1771',
        'cpm' => '3.5417',
        'actions' => [
            ['action_type' => 'lead', 'value' => '7'],
            // The same seven people, counted a second way. An allowlist is what
            // stops this being fourteen leads.
            ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '7'],
            ['action_type' => 'link_click', 'value' => '240'],
            ['action_type' => 'purchase', 'value' => '2'],
        ],
        'date_start' => $date,
    ];
}

function metaSyncUser(): User
{
    return User::factory()->create();
}

// -- The structure --------------------------------------------------------------

test('a sync reads the campaigns, ad sets and ads an account has', function () {
    $account = metaSyncAccount();

    Http::fake(metaSyncStructure());

    $counts = app(SyncMetaAdStructureAction::class)($account);

    expect($counts)->toMatchArray(['campaigns' => 1, 'ad_sets' => 1, 'ads' => 1]);

    $campaign = MetaCampaign::query()->firstOrFail();

    expect($campaign->meta_campaign_id)->toBe('23848')
        ->and($campaign->name)->toBe('Spring offer')
        ->and($campaign->objective())->toBe('OUTCOME_LEADS')
        ->and($campaign->ad_account_id)->toBe('556677')
        // Meta counts in minor units. Storing "5000" would be a hundredfold
        // error that looks entirely plausible on a screen.
        ->and((float) $campaign->daily_budget)->toBe(50.00);

    $adSet = MetaAdSet::query()->firstOrFail();

    expect($adSet->meta_campaign_id)->toBe('23848')
        // Meta spells it the American way; the column spells it the British way.
        ->and($adSet->optimisation_goal)->toBe('LEAD_GENERATION')
        ->and((float) $adSet->daily_budget)->toBe(25.00);

    $ad = MetaAd::query()->firstOrFail();

    expect($ad->meta_ad_set_id)->toBe('23849')
        ->and($ad->meta_campaign_id)->toBe('23848')
        ->and($ad->creative_summary)->toContain('Plant hire');
});

test('the tree joins on Meta ids, so an ad set that arrives first still belongs somewhere', function () {
    $account = metaSyncAccount();

    // An ad set whose campaign has not been written yet — which is what
    // pagination does routinely. With an integer foreign key this would be a
    // constraint violation or a second pass.
    MetaAdSet::factory()->create(['meta_ad_set_id' => '23849', 'meta_campaign_id' => '23848']);

    Http::fake(metaSyncStructure());

    app(SyncMetaAdStructureAction::class)($account);

    $campaign = MetaCampaign::query()->firstOrFail();

    expect($campaign->adSets()->count())->toBe(1)
        ->and($campaign->adSets()->first()?->name)->toBe('Bristol prospecting');
});

test('syncing twice leaves one of each, updated rather than duplicated', function () {
    $account = metaSyncAccount();

    // One fake with a sequence, not two calls to Http::fake(): a second call
    // **appends** its stub and the first one goes on matching — the trap
    // .ai/rules/ingestion.md records from Phase 8.
    Http::fake(metaSyncStructure([
        'graph.facebook.com/*/act_556677/campaigns*' => Http::sequence()
            ->push(['data' => [[
                'id' => '23848',
                'name' => 'Spring offer',
                'status' => 'ACTIVE',
                'daily_budget' => '5000',
            ]]])
            ->push(['data' => [[
                'id' => '23848',
                'name' => 'Spring offer (renamed)',
                'status' => 'PAUSED',
                'effective_status' => 'PAUSED',
                'daily_budget' => '7500',
            ]]]),
    ]));

    app(SyncMetaAdStructureAction::class)($account);
    app(SyncMetaAdStructureAction::class)($account);

    expect(MetaCampaign::query()->count())->toBe(1)
        ->and(MetaAdSet::query()->count())->toBe(1)
        ->and(MetaAd::query()->count())->toBe(1);

    $campaign = MetaCampaign::query()->firstOrFail();

    expect($campaign->name)->toBe('Spring offer (renamed)')
        ->and($campaign->status())->toBe('PAUSED')
        ->and((float) $campaign->daily_budget)->toBe(75.00);
});

test('a campaign missing from Meta keeps its row, its history and its link', function () {
    $account = metaSyncAccount();
    $crmCampaign = Campaign::factory()->create();

    $gone = MetaCampaign::factory()->linkedTo($crmCampaign)->create([
        'meta_campaign_id' => '11111',
        'ad_account_id' => '556677',
    ]);

    Http::fake(metaSyncStructure());

    app(SyncMetaAdStructureAction::class)($account);

    // Archived at Meta's end, or absent because a permission changed for an
    // afternoon. Deleting it would take last quarter's attribution with it.
    expect($gone->fresh())->not->toBeNull()
        ->and($gone->fresh()?->campaign_id)->toBe($crmCampaign->id);
});

test('the sync never writes the link, whatever Meta sends', function () {
    $account = metaSyncAccount();
    $crmCampaign = Campaign::factory()->create();

    MetaCampaign::factory()->linkedTo($crmCampaign)->create([
        'meta_campaign_id' => '23848',
        'ad_account_id' => '556677',
    ]);

    Http::fake(metaSyncStructure([
        'graph.facebook.com/*/act_556677/campaigns*' => Http::response(['data' => [[
            'id' => '23848',
            'name' => 'Spring offer',
            // A payload naming one of our columns. It reaches nothing: the
            // sync's write list does not contain it.
            'campaign_id' => 999999,
            'status' => 'ACTIVE',
        ]]]),
    ]));

    app(SyncMetaAdStructureAction::class)($account);

    expect(MetaCampaign::query()->firstOrFail()->campaign_id)->toBe($crmCampaign->id);
});

test('an account with no usable connection is refused rather than half read', function () {
    $account = metaSyncAccount();
    $account->account?->forceFill(['user_token' => null])->save();

    Http::fake();

    expect(fn () => app(SyncMetaAdStructureAction::class)($account->fresh()))
        ->toThrow(MetaApiException::class);

    Http::assertNothingSent();
});

// -- The figures ----------------------------------------------------------------

test('insights are stored one row per entity per day', function () {
    $account = metaSyncAccount();

    Http::fake(['graph.facebook.com/*insights*' => Http::response(['data' => [metaSyncInsightRow()]])]);

    $summary = app(SyncMetaInsightsAction::class)($account);

    // One row at each of the three levels, from the three calls.
    expect($summary['rows'])->toBe(3)
        ->and(MetaInsight::query()->count())->toBe(3);

    $campaignDay = MetaInsight::query()->atLevel(MetaAdLevel::Campaign)->firstOrFail();

    expect($campaignDay->entity_id)->toBe('23848')
        ->and((float) $campaignDay->spend)->toBe(42.50)
        ->and($campaignDay->impressions)->toBe(12000)
        ->and($campaignDay->clicks)->toBe(240)
        // Meta's own rate, not one we divided: theirs deduplicates clicks and
        // ours would disagree with Ads Manager.
        ->and((float) $campaignDay->ctr)->toBe(2.0)
        // Seven leads, reported twice by Meta and counted once here.
        ->and($campaignDay->leads)->toBe(7)
        ->and($campaignDay->conversions)->toBe(2)
        // From the ad account, so an agency's USD and BDT are never summed.
        ->and($campaignDay->currency)->toBe('USD')
        ->and($campaignDay->read_at)->not->toBeNull();

    expect(MetaInsight::query()->atLevel(MetaAdLevel::AdSet)->value('entity_id'))->toBe('23849')
        ->and(MetaInsight::query()->atLevel(MetaAdLevel::Ad)->value('entity_id'))->toBe('23850');
});

test('re-reading a day updates it rather than adding a second one', function () {
    $account = metaSyncAccount();

    // Three responses per run, one per level. The second run's figures have
    // moved, which is exactly what the restatement pass exists for.
    Http::fake([
        'graph.facebook.com/*insights*' => Http::sequence()
            ->push(['data' => [metaSyncInsightRow('2026-09-14', '42.50')]])
            ->push(['data' => [metaSyncInsightRow('2026-09-14', '42.50')]])
            ->push(['data' => [metaSyncInsightRow('2026-09-14', '42.50')]])
            ->push(['data' => [metaSyncInsightRow('2026-09-14', '61.20')]])
            ->push(['data' => [metaSyncInsightRow('2026-09-14', '61.20')]])
            ->push(['data' => [metaSyncInsightRow('2026-09-14', '61.20')]]),
    ]);

    app(SyncMetaInsightsAction::class)($account);
    app(SyncMetaInsightsAction::class)($account);

    expect(MetaInsight::query()->count())->toBe(3)
        ->and((float) MetaInsight::query()->atLevel(MetaAdLevel::Campaign)->value('spend'))->toBe(61.20);
});

test('the window reaches back over the days Meta is still restating', function () {
    $account = metaSyncAccount(['insights_synced_through' => '2026-09-14']);

    Http::fake(['graph.facebook.com/*insights*' => Http::response(['data' => []])]);

    $summary = app(SyncMetaInsightsAction::class)($account);

    // Three days behind the mark, because a day's figures are not final for
    // seventy-two hours. Asking only for what is new would under-report for
    // ever, and nothing would look wrong.
    expect($summary['from'])->toBe('2026-09-11');
});

test('a first run asks for a month rather than for everything Meta still holds', function () {
    Carbon::setTestNow('2026-09-15 09:00:00');

    $account = metaSyncAccount();

    Http::fake(['graph.facebook.com/*insights*' => Http::response(['data' => []])]);

    expect(app(SyncMetaInsightsAction::class)($account)['from'])->toBe('2026-08-16');

    Carbon::setTestNow();
});

test('the mark moves only when the whole window was read', function () {
    Carbon::setTestNow('2026-09-15 09:00:00');

    $account = metaSyncAccount(['insights_synced_through' => '2026-09-10']);

    Http::fake(['graph.facebook.com/*insights*' => Http::response(['error' => ['message' => 'Please reduce the amount of data', 'code' => 1]], 500)]);

    expect(fn () => app(SyncMetaInsightsAction::class)($account))->toThrow(MetaApiException::class);

    // Unmoved. A mark past days a failed run never fetched is a gap that is
    // permanent and silent, because nothing will ever ask for them again.
    expect($account->fresh()?->insights_synced_through?->toDateString())->toBe('2026-09-10');

    Carbon::setTestNow();
});

test('a run that stops at the rate limit does not claim to have read the window', function () {
    Carbon::setTestNow('2026-09-15 09:00:00');

    $account = metaSyncAccount(['insights_synced_through' => '2026-09-10']);

    // Meta says this call used almost all of the app's quota. Carrying on gets
    // the whole application throttled, which breaks every other integration too.
    Http::fake(['graph.facebook.com/*insights*' => Http::response(
        ['data' => [metaSyncInsightRow()]],
        200,
        ['X-App-Usage' => (string) json_encode(['call_count' => 95, 'total_time' => 20])],
    )]);

    $summary = app(SyncMetaInsightsAction::class)($account);

    expect($summary['throttled'])->toBeTrue()
        // The first level was read before Meta said how close we were; the rest
        // were not attempted.
        ->and($summary['rows'])->toBe(1)
        ->and($account->fresh()?->insights_synced_through?->toDateString())->toBe('2026-09-10');

    Carbon::setTestNow();
});

test('a structure sync stops at the rate limit rather than finishing the levels', function () {
    $account = metaSyncAccount();

    Http::fake([
        'graph.facebook.com/*/act_556677/campaigns*' => Http::response(
            ['data' => [['id' => '23848', 'name' => 'Spring offer', 'status' => 'ACTIVE']]],
            200,
            ['X-App-Usage' => (string) json_encode(['call_count' => 97])],
        ),
        'graph.facebook.com/*/act_556677/adsets*' => Http::response(['data' => [['id' => '23849', 'campaign_id' => '23848', 'name' => 'x']]]),
    ]);

    $counts = app(SyncMetaAdStructureAction::class)($account);

    expect($counts['campaigns'])->toBe(1)
        ->and($counts['throttled'])->toBeTrue()
        ->and(MetaAdSet::query()->count())->toBe(0);
});

// -- The link -------------------------------------------------------------------

test('linking a Meta campaign to a CRM campaign is recorded with who did it', function () {
    $actor = metaSyncUser();
    $crmCampaign = Campaign::factory()->create(['name' => 'Spring offer']);
    $metaCampaign = MetaCampaign::factory()->create();

    app(LinkMetaCampaignAction::class)->link($metaCampaign, $crmCampaign, $actor);

    expect($metaCampaign->fresh()?->campaign_id)->toBe($crmCampaign->id)
        ->and($metaCampaign->fresh()?->linked_by_id)->toBe($actor->id)
        ->and($metaCampaign->fresh()?->linked_at)->not->toBeNull()
        ->and($metaCampaign->fresh()?->isLinked())->toBeTrue();
});

test('linking never touches what somebody typed into actual cost', function () {
    $actor = metaSyncUser();
    $crmCampaign = Campaign::factory()->create(['actual_cost' => 1250.00]);
    $metaCampaign = MetaCampaign::factory()->create();

    MetaInsight::factory()->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)->create(['spend' => 400]);

    app(LinkMetaCampaignAction::class)->link($metaCampaign, $crmCampaign, $actor);

    // Meta's spend lives in meta_insights and is summed at read time. A column
    // a sync could overwrite is one nobody can correct.
    expect((float) $crmCampaign->fresh()?->actual_cost)->toBe(1250.00);
});

test('one CRM campaign cannot be linked to two Meta campaigns', function () {
    $actor = metaSyncUser();
    $crmCampaign = Campaign::factory()->create(['name' => 'Spring offer']);

    $first = MetaCampaign::factory()->create(['name' => 'Spring — leads']);
    $second = MetaCampaign::factory()->create(['name' => 'Spring — traffic']);

    app(LinkMetaCampaignAction::class)->link($first, $crmCampaign, $actor);

    // Refused in words rather than at the constraint: two Meta campaigns on one
    // CRM campaign would double its spend in every derived figure, with nothing
    // on the screen to say why.
    expect(fn () => app(LinkMetaCampaignAction::class)->link($second, $crmCampaign, $actor))
        ->toThrow(RuntimeException::class, 'already linked');

    expect($second->fresh()?->campaign_id)->toBeNull();
});

test('a Meta campaign already linked elsewhere says so rather than moving', function () {
    $actor = metaSyncUser();
    $metaCampaign = MetaCampaign::factory()->linkedTo(Campaign::factory()->create(['name' => 'Spring offer']))->create();

    expect(fn () => app(LinkMetaCampaignAction::class)->link($metaCampaign, Campaign::factory()->create(), $actor))
        ->toThrow(RuntimeException::class, 'Unlink it first');
});

test('linking to the same campaign twice is not an error', function () {
    $actor = metaSyncUser();
    $crmCampaign = Campaign::factory()->create();
    $metaCampaign = MetaCampaign::factory()->create();

    app(LinkMetaCampaignAction::class)->link($metaCampaign, $crmCampaign, $actor);
    app(LinkMetaCampaignAction::class)->link($metaCampaign->fresh(), $crmCampaign, $actor);

    expect(MetaCampaign::query()->linked()->count())->toBe(1);
});

test('unlinking leaves both records as they were, and lets the campaign be linked again', function () {
    $actor = metaSyncUser();
    $crmCampaign = Campaign::factory()->create();
    $first = MetaCampaign::factory()->create();
    $second = MetaCampaign::factory()->create();

    app(LinkMetaCampaignAction::class)->link($first, $crmCampaign, $actor);
    app(LinkMetaCampaignAction::class)->unlink($first->fresh(), $actor);

    expect($first->fresh()?->campaign_id)->toBeNull()
        ->and($first->fresh()?->linked_at)->toBeNull();

    // Reversible means the CRM campaign is genuinely free afterwards.
    app(LinkMetaCampaignAction::class)->link($second, $crmCampaign, $actor);

    expect($second->fresh()?->campaign_id)->toBe($crmCampaign->id);
});

test('deleting a CRM campaign unlinks rather than erasing what Meta told us', function () {
    $actor = metaSyncUser();
    $crmCampaign = Campaign::factory()->create();
    $metaCampaign = MetaCampaign::factory()->create();

    app(LinkMetaCampaignAction::class)->link($metaCampaign, $crmCampaign, $actor);

    $crmCampaign->forceDelete();

    expect($metaCampaign->fresh())->not->toBeNull()
        ->and($metaCampaign->fresh()?->campaign_id)->toBeNull();
});

// -- The schedule ----------------------------------------------------------------

test('the command queues one job per connected ad account', function () {
    metaSyncAccount();
    metaSyncAccount(['ad_account_id' => '778899']);
    MetaAdAccount::factory()->disabled()->create();

    Queue::fake();

    $this->artisan('meta:sync-ads')->assertSuccessful();

    // One per account, so an agency's ninth account does not lose its figures
    // because the third account's token expired.
    Queue::assertPushed(SyncMetaAds::class, 2);
});

test('the job reads one account and leaves the others alone', function () {
    $account = metaSyncAccount();

    Http::fake([
        ...metaSyncStructure(),
        'graph.facebook.com/*insights*' => Http::response(['data' => [metaSyncInsightRow()]]),
    ]);

    app()->call([new SyncMetaAds($account->id), 'handle']);

    expect(MetaCampaign::query()->count())->toBe(1)
        ->and(MetaInsight::query()->count())->toBe(3)
        ->and($account->fresh()?->insights_synced_through)->not->toBeNull();
});
