<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Meta\Enums\MetaAdLevel;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Models\MetaInsight;
use App\Livewire\Meta\MetaCampaigns;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * Task 12.7 — the screen the link is made on.
 */
function metaCampaignUser(array $permissions = ['meta.campaigns.view', 'meta.campaigns.manage']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

test('the screen lists what Meta spent beside the campaign it belongs to', function () {
    $crmCampaign = Campaign::factory()->create(['name' => 'Spring offer']);
    $metaCampaign = MetaCampaign::factory()->linkedTo($crmCampaign)->create(['name' => 'Spring — leads']);

    MetaInsight::factory()
        ->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)
        ->create(['spend' => 120.50, 'leads' => 9, 'currency' => 'GBP']);

    Livewire::actingAs(metaCampaignUser())
        ->test(MetaCampaigns::class)
        ->assertOk()
        ->assertSee('Spring — leads')
        ->assertSee('Spring offer')
        ->assertSee('120.50')
        ->assertSee('9');
});

test('spend is summed per campaign in one query, not one per row', function () {
    $metaCampaign = MetaCampaign::factory()->create();

    MetaInsight::factory()
        ->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)
        ->on('2026-09-13')->create(['spend' => 100.00, 'leads' => 4]);

    MetaInsight::factory()
        ->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)
        ->on('2026-09-14')->create(['spend' => 50.00, 'leads' => 3]);

    Livewire::actingAs(metaCampaignUser())
        ->test(MetaCampaigns::class)
        ->assertSee('150.00')
        ->assertSee('7');
});

test('a campaign nobody has linked says so', function () {
    MetaCampaign::factory()->create(['name' => 'Spring — traffic']);

    Livewire::actingAs(metaCampaignUser())
        ->test(MetaCampaigns::class)
        ->assertSee('Not linked');
});

test('linking from the screen ties the two together', function () {
    $user = metaCampaignUser();
    // Owned by the person doing the linking: a user with no role sees only
    // their own records, which is the default this application ships.
    $crmCampaign = Campaign::factory()->create(['name' => 'Spring offer', 'owner_id' => $user->id]);
    $metaCampaign = MetaCampaign::factory()->create();

    Livewire::actingAs($user)
        ->test(MetaCampaigns::class)
        ->call('startLinking', $metaCampaign->id)
        ->set('chosenCampaignId', (string) $crmCampaign->id)
        ->call('link')
        ->assertHasNoErrors();

    expect($metaCampaign->fresh()?->campaign_id)->toBe($crmCampaign->id);
});

test('the screen reports a refusal rather than failing', function () {
    $user = metaCampaignUser();
    $crmCampaign = Campaign::factory()->create(['name' => 'Spring offer', 'owner_id' => $user->id]);

    MetaCampaign::factory()->linkedTo($crmCampaign)->create(['name' => 'Spring — leads']);
    $second = MetaCampaign::factory()->create(['name' => 'Spring — traffic']);

    // The option list already excludes a campaign that is taken, so reaching the
    // action means somebody submitted an id the screen did not offer. It still
    // refuses, in words — and the assertion names the action's own sentence
    // rather than a phrase the empty-state also uses.
    Livewire::actingAs($user)
        ->test(MetaCampaigns::class)
        ->call('startLinking', $second->id)
        ->set('chosenCampaignId', (string) $crmCampaign->id)
        ->call('link')
        ->assertSee('is already linked to the Meta campaign');

    expect($second->fresh()?->campaign_id)->toBeNull();
});

test('a campaign already linked is not offered again', function () {
    $user = metaCampaignUser();
    $taken = Campaign::factory()->create(['name' => 'Spring offer', 'owner_id' => $user->id]);
    $free = Campaign::factory()->create(['name' => 'Autumn offer', 'owner_id' => $user->id]);

    MetaCampaign::factory()->linkedTo($taken)->create();
    $second = MetaCampaign::factory()->create();

    $options = Livewire::actingAs($user)
        ->test(MetaCampaigns::class)
        ->call('startLinking', $second->id)
        ->instance()
        ->campaignOptions();

    expect($options)->toHaveKey($free->id)
        ->and($options)->not->toHaveKey($taken->id);
});

test('unlinking from the screen frees the campaign', function () {
    $user = metaCampaignUser();
    $crmCampaign = Campaign::factory()->create(['owner_id' => $user->id]);
    $metaCampaign = MetaCampaign::factory()->linkedTo($crmCampaign)->create();

    Livewire::actingAs($user)
        ->test(MetaCampaigns::class)
        ->call('unlink', $metaCampaign->id);

    expect($metaCampaign->fresh()?->campaign_id)->toBeNull();
});

test('a campaign outside the viewer\'s access level cannot be linked to', function () {
    // `exists` proves a campaign is real, never that this person may reach it.
    $somebodyElses = Campaign::factory()->create(['owner_id' => User::factory()->create()->id]);
    $metaCampaign = MetaCampaign::factory()->create();

    $viewer = metaCampaignUser();
    // The default role's access level is "own", so another person's campaign is
    // outside it.
    Livewire::actingAs($viewer)
        ->test(MetaCampaigns::class)
        ->call('startLinking', $metaCampaign->id)
        ->set('chosenCampaignId', (string) $somebodyElses->id)
        ->call('link');

    expect($metaCampaign->fresh()?->campaign_id)->toBeNull();
});

test('reading may not link', function () {
    $metaCampaign = MetaCampaign::factory()->create();

    Livewire::actingAs(metaCampaignUser(['meta.campaigns.view']))
        ->test(MetaCampaigns::class)
        ->assertOk()
        ->call('startLinking', $metaCampaign->id)
        ->assertForbidden();
});

test('somebody without the permission cannot open it at all', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(MetaCampaigns::class)
        ->assertForbidden();
});

test('a status chip renders the label it was given', function () {
    // It did not, before 12.7: `label` was undeclared, so it became an HTML
    // attribute and the chip rendered empty on two shipped screens.
    expect(Blade::render('<x-status-chip label="Connected" color="emerald" />'))
        ->toContain('Connected')
        ->not->toContain('label="Connected"');
});

test('the delivery figures sit beside the money', function () {
    $metaCampaign = MetaCampaign::factory()->create(['name' => 'Monsoon plant hire']);

    MetaInsight::factory()
        ->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)
        ->create([
            'spend' => 100.00,
            'impressions' => 20000,
            'reach' => 14000,
            'clicks' => 400,
            'leads' => 9,
            'currency' => 'BDT',
        ]);

    Livewire::actingAs(metaCampaignUser())
        ->test(MetaCampaigns::class)
        ->assertSee('20,000')
        ->assertSee('14,000')
        ->assertSee('400')
        // 400 of 20,000 — computed here rather than summed from Meta's own
        // daily rate, because rates do not add.
        ->assertSee('2.00%')
        // 100.00 over 400 clicks.
        ->assertSee('BDT 0.25');
});

test('a rate over a period is clicks over impressions, never a sum of daily rates', function () {
    $metaCampaign = MetaCampaign::factory()->create();

    // A quiet day and a busy one. Summing Meta's per-day CTR would weigh them
    // equally and report 25.5%; the truth is 1,001 clicks in 100,002
    // impressions.
    MetaInsight::factory()
        ->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)
        ->on('2026-09-13')
        ->create(['spend' => 0.10, 'impressions' => 2, 'clicks' => 1, 'ctr' => 50.0]);

    MetaInsight::factory()
        ->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)
        ->on('2026-09-14')
        ->create(['spend' => 500.00, 'impressions' => 100000, 'clicks' => 1000, 'ctr' => 1.0]);

    $figures = Livewire::actingAs(metaCampaignUser())
        ->test(MetaCampaigns::class)
        ->instance()
        ->figuresFor(MetaCampaign::query()->get());

    $figure = $figures[$metaCampaign->meta_campaign_id];

    expect($figure['ctr'])->toBe(1.0)
        ->and($figure['clicks'])->toBe(1001)
        ->and($figure['impressions'])->toBe(100002);
});

test('a campaign nobody has seen has no rate rather than a nought', function () {
    $metaCampaign = MetaCampaign::factory()->create();

    MetaInsight::factory()
        ->atLevel(MetaAdLevel::Campaign, $metaCampaign->meta_campaign_id)
        ->create(['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0]);

    $figures = Livewire::actingAs(metaCampaignUser())
        ->test(MetaCampaigns::class)
        ->instance()
        ->figuresFor(MetaCampaign::query()->get());

    // 0% would be a claim about how it performed.
    expect($figures[$metaCampaign->meta_campaign_id]['ctr'])->toBeNull()
        ->and($figures[$metaCampaign->meta_campaign_id]['cpc'])->toBeNull();
});
