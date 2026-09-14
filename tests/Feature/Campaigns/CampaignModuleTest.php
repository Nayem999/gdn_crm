<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Campaigns\Actions\SaveCampaignAction;
use App\Domain\Campaigns\DTOs\CampaignData;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\CampaignType;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Livewire\Campaigns\CampaignForm;
use App\Livewire\Campaigns\CampaignShow;
use App\Livewire\Campaigns\CampaignsIndex;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Task 12.2 — the CRM's own campaigns.
 *
 * The module the whole Meta phase leans on: Meta spend is only a useful number
 * when it sits beside every other channel's cost, against the same leads.
 */
function campaignUser(array $permissions = ['campaigns.view'], DataAccessLevel $level = DataAccessLevel::All): User
{
    $user = User::factory()->create();

    $role = Role::query()->create([
        'name' => 'Campaign role '.uniqid(),
        'guard_name' => 'web',
        'data_access_level' => $level->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user->assignRole($role);

    return $user->fresh();
}

// -- The record ----------------------------------------------------------------

test('a campaign carries what it cost and what it was budgeted at', function () {
    $campaign = Campaign::factory()->costing(4000, budget: 10000)->create();

    expect($campaign->cost())->toBe(4000.0)
        ->and($campaign->budget())->toBe(10000.0)
        ->and($campaign->budgetUsedPercent())->toBe(40.0);
});

test('overspending reads as overspending rather than being capped', function () {
    $campaign = Campaign::factory()->costing(15000, budget: 10000)->create();

    // A bar pinned at 100% would hide exactly the campaigns somebody needs to
    // look at.
    expect($campaign->budgetUsedPercent())->toBe(150.0);
});

test('a campaign with no budget has no percentage rather than an infinite one', function () {
    $campaign = Campaign::factory()->unbudgeted()->create();

    expect($campaign->budget())->toBeNull()
        ->and($campaign->cost())->toBe(0.0)
        ->and($campaign->budgetUsedPercent())->toBeNull();
});

test('running is a question about dates, not only about status', function () {
    $forgotten = Campaign::factory()->create([
        'status' => CampaignStatus::Active->value,
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->subMonths(6)->toDateString(),
    ]);

    // Somebody forgot to mark it Completed. The status says active; the dates
    // say it finished half a year ago, and a list that could not tell them
    // apart is a list nobody trusts.
    expect($forgotten->status()->isRunning())->toBeTrue()
        ->and($forgotten->isWithinDates())->toBeFalse();
});

test('a type that cannot be a Meta campaign says so', function () {
    // Linking an email campaign to an ad set would attribute one channel's
    // leads to another's spend.
    expect(CampaignType::PaidSocial->acceptsMetaLink())->toBeTrue()
        ->and(CampaignType::Email->acceptsMetaLink())->toBeFalse()
        ->and(CampaignType::Event->acceptsMetaLink())->toBeFalse();
});

// -- Saving --------------------------------------------------------------------

test('a campaign that ends before it starts is refused', function () {
    $action = app(SaveCampaignAction::class);

    $data = CampaignData::fromArray([
        'name' => 'Backwards',
        'type' => CampaignType::Email->value,
        'status' => CampaignStatus::Planned->value,
        'start_date' => '2026-06-01',
        'end_date' => '2026-05-01',
        'owner_id' => User::factory()->create()->id,
    ]);

    // Every figure this module produces divides by the window between the two
    // dates, and a negative one is not a mistake anybody notices in the number
    // it produces.
    expect(fn () => $action($data))->toThrow(RuntimeException::class, 'end date cannot be before');
});

test('two campaigns cannot share a code, removed ones included', function () {
    $owner = User::factory()->create();
    $existing = Campaign::factory()->create(['code' => 'CMP-0001']);
    $existing->delete();

    $action = app(SaveCampaignAction::class);

    $data = CampaignData::fromArray([
        'name' => 'Second',
        'type' => CampaignType::Event->value,
        'status' => CampaignStatus::Planned->value,
        'code' => 'cmp-0001',
        'owner_id' => $owner->id,
    ]);

    // Lowercase in, uppercase stored: a code is a code, and two campaigns
    // answering to one is how attribution stops adding up. The unique index
    // covers soft-deleted rows, so being told here beats a database error.
    expect(fn () => $action($data))->toThrow(RuntimeException::class, 'CMP-0001');
});

test('an edit that leaves the owner alone does not unassign the campaign', function () {
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->ownedBy($owner)->create();

    $saved = app(SaveCampaignAction::class)(CampaignData::fromArray([
        'name' => 'Renamed',
        'type' => $campaign->type->value ?? CampaignType::Other->value,
        'status' => CampaignStatus::Active->value,
        'owner_id' => '',
    ]), $campaign);

    expect($saved->owner_id)->toBe($owner->id)
        ->and($saved->name)->toBe('Renamed');
});

// -- Attribution ---------------------------------------------------------------

test('leads, contacts and deals all point at a campaign', function () {
    $campaign = Campaign::factory()->create();

    $lead = Lead::factory()->create(['campaign_id' => $campaign->id]);
    $contact = Contact::factory()->create(['campaign_id' => $campaign->id]);
    $deal = Deal::factory()->create(['campaign_id' => $campaign->id]);

    expect($campaign->leads()->count())->toBe(1)
        ->and($campaign->contacts()->count())->toBe(1)
        ->and($campaign->deals()->count())->toBe(1)
        ->and($lead->campaign->is($campaign))->toBeTrue()
        ->and($contact->campaign->is($campaign))->toBeTrue()
        ->and($deal->campaign->is($campaign))->toBeTrue();
});

test('removing a campaign keeps what it won', function () {
    $campaign = Campaign::factory()->create();
    $lead = Lead::factory()->create(['campaign_id' => $campaign->id]);

    $campaign->forceDelete();

    // Nulled rather than cascaded: a lead whose campaign has been deleted is
    // still a lead, and attribution is a label on work that happened rather
    // than something the work depends on.
    expect($lead->fresh())->not->toBeNull()
        ->and($lead->fresh()->campaign_id)->toBeNull();
});

test('attributable campaigns are the ones a new lead could plausibly have come from', function () {
    $running = Campaign::factory()->running()->create();
    // Dates named here rather than taken from the factory: the grace window is
    // the thing being tested, and a state meaning "finished a while ago" sits
    // right on its edge.
    $recentlyFinished = Campaign::factory()->create([
        'status' => CampaignStatus::Completed->value,
        'end_date' => now()->subDays(10)->toDateString(),
    ]);
    $planned = Campaign::factory()->withStatus(CampaignStatus::Planned)->create();
    $longOver = Campaign::factory()->create([
        'status' => CampaignStatus::Completed->value,
        'end_date' => now()->subYear()->toDateString(),
    ]);

    $keys = Campaign::query()->attributable()->pluck('id');

    expect($keys)->toContain($running->id)
        ->toContain($recentlyFinished->id)
        ->not->toContain($planned->id)
        ->not->toContain($longOver->id);

    // The window is a parameter, not a constant somebody has to guess at.
    expect(Campaign::query()->attributable(graceDays: 400)->pluck('id'))->toContain($longOver->id);
});

// -- Access -------------------------------------------------------------------

test('somebody with no campaign permission cannot open the list', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(CampaignsIndex::class)
        ->assertForbidden();
});

test('own access shows only your own campaigns', function () {
    $mine = campaignUser(['campaigns.view'], DataAccessLevel::Own);
    Campaign::factory()->ownedBy($mine)->create(['name' => 'Mine']);
    Campaign::factory()->create(['name' => 'Somebody else\'s']);

    Livewire::actingAs($mine)
        ->test(CampaignsIndex::class)
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Somebody else');
});

test('the figures on the page are scoped the same way the lists are', function () {
    $viewer = campaignUser(['campaigns.view', 'leads.view', 'deals.view'], DataAccessLevel::Own);
    $campaign = Campaign::factory()->ownedBy($viewer)->create();

    Lead::factory()->ownedBy($viewer)->create(['campaign_id' => $campaign->id]);
    Lead::factory()->create(['campaign_id' => $campaign->id]);

    $figures = Livewire::actingAs($viewer)
        ->test(CampaignShow::class, ['campaign' => $campaign])
        ->assertOk()
        ->instance()
        ->figures();

    // A total that included records the viewer may not open would leak the
    // shape of other people's pipelines.
    expect($figures['leads'])->toBe(1);
});

test('a campaign cannot be edited by somebody who may only view', function () {
    $viewer = campaignUser(['campaigns.view']);
    $campaign = Campaign::factory()->create();

    Livewire::actingAs($viewer)
        ->test(CampaignForm::class, ['campaign' => $campaign])
        ->assertForbidden();
});

// -- The screens ---------------------------------------------------------------

test('the list opens and shows a campaign', function () {
    $user = campaignUser();
    Campaign::factory()->create(['name' => 'Summer pump push']);

    Livewire::actingAs($user)
        ->test(CampaignsIndex::class)
        ->assertOk()
        ->assertSee('Summer pump push');
});

test('the over-budget chip finds campaigns that have overspent', function () {
    $user = campaignUser();
    Campaign::factory()->costing(20000, budget: 10000)->create(['name' => 'Overspent one']);
    Campaign::factory()->costing(1000, budget: 10000)->create(['name' => 'Within budget']);

    Livewire::actingAs($user)
        ->test(CampaignsIndex::class)
        ->call('setQuickFilter', 'overspent')
        ->assertSee('Overspent one')
        ->assertDontSee('Within budget');
});

test('the form creates a campaign and lands on it', function () {
    $user = campaignUser(['campaigns.view', 'campaigns.create']);

    Livewire::actingAs($user)
        ->test(CampaignForm::class)
        ->set('name', 'Winter service offer')
        ->set('type', CampaignType::PaidSocial->value)
        ->set('status', CampaignStatus::Active->value)
        ->set('budget', '25000')
        ->set('startDate', now()->toDateString())
        ->set('endDate', now()->addMonth()->toDateString())
        ->set('ownerId', (string) $user->id)
        ->call('save')
        ->assertHasNoErrors();

    $campaign = Campaign::query()->firstWhere('name', 'Winter service offer');

    expect($campaign)->not->toBeNull()
        ->and($campaign->type())->toBe(CampaignType::PaidSocial)
        ->and($campaign->budget())->toBe(25000.0);
});

test('the form refuses an end date before the start date', function () {
    $user = campaignUser(['campaigns.view', 'campaigns.create']);

    Livewire::actingAs($user)
        ->test(CampaignForm::class)
        ->set('name', 'Backwards')
        ->set('startDate', '2026-06-01')
        ->set('endDate', '2026-05-01')
        ->set('ownerId', (string) $user->id)
        ->call('save')
        ->assertHasErrors('endDate');
});

test('the show page counts what the campaign produced', function () {
    $user = campaignUser(['campaigns.view', 'leads.view', 'deals.view']);
    $campaign = Campaign::factory()->costing(5000)->create();

    Lead::factory()->count(4)->create(['campaign_id' => $campaign->id]);

    $figures = Livewire::actingAs($user)
        ->test(CampaignShow::class, ['campaign' => $campaign])
        ->assertOk()
        ->instance()
        ->figures();

    expect($figures['leads'])->toBe(4)
        ->and($figures['cost'])->toBe(5000.0)
        ->and($figures['cost_per_lead'])->toBe(1250.0);
});

test('a campaign with no cost has no cost per lead rather than a free one', function () {
    $user = campaignUser(['campaigns.view', 'leads.view', 'deals.view']);
    $campaign = Campaign::factory()->unbudgeted()->create();

    Lead::factory()->count(2)->create(['campaign_id' => $campaign->id]);

    $figures = Livewire::actingAs($user)
        ->test(CampaignShow::class, ['campaign' => $campaign])
        ->instance()
        ->figures();

    // 0.00 would read as "free", which is a different claim from "not recorded".
    expect($figures['cost_per_lead'])->toBeNull()
        ->and($figures['roi'])->toBeNull();
});

test('a campaign can be removed, and the confirmation says what loses its attribution', function () {
    $user = campaignUser(['campaigns.view', 'campaigns.delete']);
    $campaign = Campaign::factory()->create();
    Lead::factory()->count(3)->create(['campaign_id' => $campaign->id]);

    $component = Livewire::actingAs($user)->test(CampaignShow::class, ['campaign' => $campaign]);

    expect($component->instance()->deletionImpact()['leads'])->toBe(3);

    $component->call('delete');

    expect($campaign->fresh()->trashed())->toBeTrue();
});
