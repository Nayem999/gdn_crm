<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Leads\LeadForm;
use App\Models\User;
use Livewire\Livewire;

function leadAttributionUser(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['leads.view', 'leads.create', 'leads.update', 'campaigns.view']) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

test('a lead captured from a campaign is attributed to it', function () {
    $user = leadAttributionUser();
    $campaign = Campaign::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Rahim')
        ->set('last_name', 'Uddin')
        ->set('email', 'rahim@example.com')
        ->set('campaign_id', (string) $campaign->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->campaign_id)->toBe($campaign->id)
        ->and($campaign->leads()->count())->toBe(1);
});

test('the form offers only campaigns the person can see', function () {
    $user = leadAttributionUser();
    $mine = Campaign::factory()->ownedBy($user)->create(['name' => 'Mine campaign']);
    Campaign::factory()->create(['name' => 'Someone else campaign']);

    $options = Livewire::actingAs($user)->test(LeadForm::class)->instance()->campaignOptions();

    expect($options)->toBe([$mine->id => 'Mine campaign']);
});

test('a campaign outside the access level is refused even when posted directly', function () {
    $user = leadAttributionUser();
    $hidden = Campaign::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Rahim')
        ->set('last_name', 'Uddin')
        ->set('email', 'rahim@example.com')
        ->set('campaign_id', (string) $hidden->id)
        ->call('save')
        ->assertHasErrors('campaign_id');

    expect(Lead::query()->count())->toBe(0);
});

test('editing a lead keeps an attribution the editor cannot see', function () {
    // Attributed by somebody else (or by an ad click); an unrelated edit must
    // neither fail over it nor drop it.
    $user = leadAttributionUser();
    $theirs = Campaign::factory()->create();
    $lead = Lead::factory()->ownedBy($user)->create(['campaign_id' => $theirs->id]);

    Livewire::actingAs($user)
        ->test(LeadForm::class, ['lead' => $lead])
        ->set('job_title', 'Managing Director')
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->fresh()->campaign_id)->toBe($theirs->id)
        ->and($lead->fresh()->job_title)->toBe('Managing Director');
});

test('clearing the campaign removes the attribution', function () {
    $user = leadAttributionUser();
    $campaign = Campaign::factory()->ownedBy($user)->create();
    $lead = Lead::factory()->ownedBy($user)->create(['campaign_id' => $campaign->id]);

    Livewire::actingAs($user)
        ->test(LeadForm::class, ['lead' => $lead])
        ->set('campaign_id', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->fresh()->campaign_id)->toBeNull();
});

test('an update that says nothing about the campaign leaves it alone', function () {
    // Capture forms, ingestion and Meta updates never carry a campaign key;
    // reading that as "clear it" would wipe what an ad click recorded.
    $campaign = Campaign::factory()->create();
    $lead = Lead::factory()->create(['campaign_id' => $campaign->id]);

    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        'first_name' => $lead->first_name,
        'last_name' => $lead->last_name,
        'email' => 'updated@example.com',
    ]));

    expect($lead->fresh()->campaign_id)->toBe($campaign->id)
        ->and($lead->fresh()->email)->toBe('updated@example.com');
});
