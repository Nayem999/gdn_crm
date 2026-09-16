<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Dashboard\DashboardKpis;
use App\Domain\Dashboard\DashboardScope;
use App\Domain\Dashboard\Enums\DashboardPeriod;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Analytics\CampaignPerformance;
use App\Domain\Meta\Enums\MetaAdLevel;
use App\Domain\Meta\Models\MetaAd;
use App\Domain\Meta\Models\MetaAdSet;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Models\MetaInsight;
use App\Livewire\Meta\MetaPerformance;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Task 12.13 — the chain from what Meta charged to what the CRM earned.
 *
 * Every figure here is computed from stored rows. Nothing calls Meta, and the
 * tests would fail loudly if anything tried: the point of the screen is that it
 * answers the same way for two people looking at once, and a live call could
 * not.
 */
function adPerfUser(array $permissions = ['meta.campaigns.view', 'leads.view', 'deals.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

/**
 * One campaign, one ad set, one advertisement — the shape 12.7 syncs.
 */
function adPerfCampaign(string $id = '120200000000001'): MetaAd
{
    MetaCampaign::query()->create([
        'meta_campaign_id' => $id,
        'ad_account_id' => '23914816791552795',
        'name' => 'Monsoon plant hire',
    ]);

    MetaAdSet::query()->create([
        'meta_ad_set_id' => $id.'-set',
        'meta_campaign_id' => $id,
        'name' => 'Dhaka — contractors',
    ]);

    return MetaAd::query()->create([
        'meta_ad_id' => $id.'-ad',
        'meta_ad_set_id' => $id.'-set',
        'meta_campaign_id' => $id,
        'name' => 'Digger — carousel',
    ]);
}

function adPerfSpend(string $entityId, MetaAdLevel $level, float $spend, string $date = '2026-09-10', int $reportedLeads = 0): void
{
    MetaInsight::query()->create([
        'level' => $level->value,
        'entity_id' => $entityId,
        'date' => $date,
        'spend' => $spend,
        'impressions' => 1000,
        'clicks' => 40,
        'leads' => $reportedLeads,
        'currency' => 'BDT',
        'read_at' => now(),
    ]);
}

/**
 * A lead this advertising produced, with its attribution.
 */
function adPerfLead(User $owner, string $campaignId, string $status = 'new', string $capturedAt = '2026-09-11'): Lead
{
    $lead = Lead::factory()->ownedBy($owner)->create(['status' => $status]);

    $lead->recordAttribution(new MarketingAttribution(
        source: 'facebook_ads',
        metaCampaignId: $campaignId,
        metaAdSetId: $campaignId.'-set',
        metaAdId: $campaignId.'-ad',
        capturedAt: Carbon::parse($capturedAt),
    ));

    return $lead->fresh();
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-16 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The arithmetic ------------------------------------------------------------

test('the chain runs from spend through leads to revenue', function () {
    $user = adPerfUser();
    adPerfCampaign();
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 40000);

    // Four leads, two qualified, one of which was won for 200,000.
    adPerfLead($user, '120200000000001');
    adPerfLead($user, '120200000000001');
    adPerfLead($user, '120200000000001', LeadStatus::Qualified->value);
    $won = adPerfLead($user, '120200000000001', LeadStatus::Converted->value);

    $deal = Deal::factory()->create(['value' => 200000, 'stage' => DealStage::Won->value, 'owner_id' => $user->id]);
    $won->copyAttributionTo($deal);

    $row = app(CampaignPerformance::class)
        ->campaigns($user, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
        ->firstOrFail();

    expect($row->spend)->toBe(40000.0)
        ->and($row->leads)->toBe(4)
        // Converted counts as qualified: a lead that became a customer was
        // self-evidently worth talking to, and excluding it would make the
        // best campaigns look worst.
        ->and($row->qualified)->toBe(2)
        ->and($row->won)->toBe(1)
        ->and($row->revenue)->toBe(200000.0)
        ->and($row->costPerLead())->toBe(10000.0)
        ->and($row->costPerQualifiedLead())->toBe(20000.0)
        ->and($row->costPerAcquisition())->toBe(40000.0)
        ->and($row->conversionRate())->toBe(25.0)
        // (200,000 - 40,000) / 40,000 — four hundred per cent, not five.
        ->and($row->roi())->toBe(400.0);
});

test('a figure with no denominator is a dash rather than a zero', function () {
    $user = adPerfUser();
    adPerfCampaign();
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 40000);

    $row = app(CampaignPerformance::class)
        ->campaigns($user, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
        ->firstOrFail();

    // A campaign that has spent and produced nothing has *no* cost per lead.
    // Printing the spend would be wrong and printing zero would be wrong in the
    // other direction.
    expect($row->costPerLead())->toBeNull()
        ->and($row->costPerAcquisition())->toBeNull()
        ->and($row->conversionRate())->toBeNull()
        // ROI is answerable: it lost everything.
        ->and($row->roi())->toBe(-100.0);
});

test('a campaign with leads and no spend has no ROI', function () {
    $user = adPerfUser();
    adPerfCampaign();
    adPerfLead($user, '120200000000001');

    $row = app(CampaignPerformance::class)
        ->campaigns($user, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
        ->firstOrFail();

    expect($row->leads)->toBe(1)
        ->and($row->spend)->toBe(0.0)
        ->and($row->roi())->toBeNull();
});

// -- What each viewer may count ------------------------------------------------

test('revenue is scoped to the viewer even though the spend is not', function () {
    $mine = adPerfUser();
    $theirs = adPerfUser();

    adPerfCampaign();
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 40000);

    // Somebody else's lead, from the same advertising.
    $other = adPerfLead($theirs, '120200000000001', LeadStatus::Converted->value);
    $deal = Deal::factory()->create(['value' => 500000, 'stage' => DealStage::Won->value, 'owner_id' => $theirs->id]);
    $other->copyAttributionTo($deal);

    $row = app(CampaignPerformance::class)
        ->campaigns($mine, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
        ->firstOrFail();

    // Meta charged the company, not a salesperson, so the spend is whole — but
    // "500,000 of revenue" is information about a deal this person may not
    // open, and an aggregate is the easiest kind of leak to miss.
    expect($row->spend)->toBe(40000.0)
        ->and($row->revenue)->toBe(0.0)
        ->and($row->leads)->toBe(0);
});

// -- The window ----------------------------------------------------------------

test('the period decides what is counted, on each side by its own date', function () {
    $user = adPerfUser();
    adPerfCampaign();

    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 10000, '2026-08-20');
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 30000, '2026-09-10');

    adPerfLead($user, '120200000000001', 'new', '2026-08-21');
    adPerfLead($user, '120200000000001', 'new', '2026-09-11');

    $september = app(CampaignPerformance::class)
        ->campaigns($user, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
        ->firstOrFail();

    expect($september->spend)->toBe(30000.0)
        ->and($september->leads)->toBe(1);
});

// -- Drilling down -------------------------------------------------------------

test('an ad set and an advertisement answer the same questions as the campaign', function () {
    $user = adPerfUser();
    adPerfCampaign();

    adPerfSpend('120200000000001-set', MetaAdLevel::AdSet, 25000);
    adPerfSpend('120200000000001-ad', MetaAdLevel::Ad, 25000);
    adPerfLead($user, '120200000000001');

    $performance = app(CampaignPerformance::class);
    $from = Carbon::parse('2026-09-01');
    $to = Carbon::parse('2026-09-30');

    $adSet = $performance->adSets($user, '120200000000001', $from, $to)->firstOrFail();
    $ad = $performance->ads($user, '120200000000001-set', $from, $to)->firstOrFail();

    expect($adSet->name)->toBe('Dhaka — contractors')
        ->and($adSet->spend)->toBe(25000.0)
        ->and($adSet->leads)->toBe(1)
        ->and($ad->name)->toBe('Digger — carousel')
        ->and($ad->leads)->toBe(1);
});

test('an advertisement that has not been synced is still counted, under its id', function () {
    $user = adPerfUser();

    // Spend arrived before the structure sync ran, which is ordinary: the two
    // are separate calls and Meta answers them at its own pace.
    adPerfSpend('999888777', MetaAdLevel::Campaign, 5000);

    $row = app(CampaignPerformance::class)
        ->campaigns($user, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
        ->firstOrFail();

    expect($row->spend)->toBe(5000.0)
        ->and($row->name)->toContain('999888777');
});

// -- Meta's own count ----------------------------------------------------------

test('a gap between Meta\'s lead count and the CRM\'s is surfaced, not hidden', function () {
    $user = adPerfUser();
    adPerfCampaign();

    // Meta counts a lead when the form is submitted; this CRM counts one when
    // it arrives. A gap means deliveries are being lost.
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 40000, '2026-09-10', reportedLeads: 7);
    adPerfLead($user, '120200000000001');

    $row = app(CampaignPerformance::class)
        ->campaigns($user, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
        ->firstOrFail();

    expect($row->reportedLeads)->toBe(7)
        ->and($row->leads)->toBe(1)
        ->and($row->hasLeadGap())->toBeTrue();
});

// -- The screen ----------------------------------------------------------------

test('the screen shows the chain and drills into a campaign', function () {
    $user = adPerfUser();
    adPerfCampaign();
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 40000);
    adPerfSpend('120200000000001-set', MetaAdLevel::AdSet, 40000);
    adPerfLead($user, '120200000000001');

    Livewire::actingAs($user)
        ->test(MetaPerformance::class)
        ->assertSee('Monsoon plant hire')
        ->assertSee('BDT 40,000.00')
        ->assertSee('Campaigns')
        ->call('openCampaign', '120200000000001')
        ->assertSee('Ad sets')
        ->assertSee('Dhaka — contractors');
});

test('reading the figures needs the Meta campaigns permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(MetaPerformance::class)
        ->assertForbidden();
});

test('a range the wrong way round is corrected rather than shown empty', function () {
    $user = adPerfUser();

    Livewire::actingAs($user)
        ->test(MetaPerformance::class)
        ->set('from', '2026-09-20')
        ->set('to', '2026-09-01')
        // An empty table with no explanation reads as a broken integration
        // rather than a typing mistake.
        ->assertSet('to', '2026-09-20');
});

// -- The dashboard -------------------------------------------------------------

test('the dashboard carries the spend and the cost per lead', function () {
    $user = adPerfUser(['meta.campaigns.view', 'leads.view', 'deals.view', 'activities.view']);
    adPerfCampaign();
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 40000, Carbon::now()->toDateString());
    adPerfLead($user, '120200000000001', 'new', Carbon::now()->toDateString());
    adPerfLead($user, '120200000000001', 'new', Carbon::now()->toDateString());

    $kpis = collect(app(DashboardKpis::class)->for(
        new DashboardScope($user),
        DashboardPeriod::Month,
    ))->keyBy('key');

    expect($kpis->has('meta_spend'))->toBeTrue()
        ->and($kpis->get('meta_spend')->raw)->toBe(40000.0)
        ->and($kpis->get('meta_cost_per_lead')->raw)->toBe(20000.0);
});

test('somebody who cannot see campaign figures gets no Meta cards', function () {
    $user = adPerfUser(['leads.view']);
    adPerfCampaign();
    adPerfSpend('120200000000001', MetaAdLevel::Campaign, 40000, Carbon::now()->toDateString());

    $keys = collect(app(DashboardKpis::class)->for(
        new DashboardScope($user),
        DashboardPeriod::Month,
    ))->pluck('key');

    expect($keys)->not->toContain('meta_spend');
});

test('an installation that does not advertise carries no empty cards', function () {
    $user = adPerfUser();

    // No spend and no leads: two permanently empty cards would say the
    // advertising produced nothing rather than that there was none.
    $keys = collect(app(DashboardKpis::class)->for(
        new DashboardScope($user),
        DashboardPeriod::Month,
    ))->pluck('key');

    expect($keys)->not->toContain('meta_spend');
});
