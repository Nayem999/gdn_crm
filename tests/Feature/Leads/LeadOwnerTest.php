<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Leads\LeadsIndex;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

function leadOwnerEditor(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['leads.view', 'leads.create', 'leads.update']) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

test('a lead has no owner unless somebody picks one', function () {
    $user = leadOwnerEditor();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->assertSet('lead_owner_id', null)
        ->set('first_name', 'Rahim')
        ->set('last_name', 'Uddin')
        ->set('email', 'rahim@example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->lead_owner_id)->toBeNull();
});

test('the form sets an owner who need not be an assignee', function () {
    $user = leadOwnerEditor();
    $manager = User::factory()->create(['name' => 'Sales Manager']);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Rahim')
        ->set('last_name', 'Uddin')
        ->set('email', 'rahim@example.com')
        ->set('lead_owner_id', (string) $manager->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->lead_owner_id)->toBe($manager->id)
        ->and(leadAssigneeIds($lead))->toBe([$user->id]);
});

test('an owner that is not a real user is refused', function () {
    Livewire::actingAs(leadOwnerEditor())
        ->test(LeadForm::class)
        ->set('first_name', 'Rahim')
        ->set('last_name', 'Uddin')
        ->set('email', 'rahim@example.com')
        ->set('lead_owner_id', '999999')
        ->call('save')
        ->assertHasErrors('lead_owner_id');
});

test('the owner can be cleared again', function () {
    $user = leadOwnerEditor();
    $lead = Lead::factory()->ownedBy($user)->create(['lead_owner_id' => User::factory()->create()->id]);

    Livewire::actingAs($user)
        ->test(LeadForm::class, ['lead' => $lead])
        ->set('lead_owner_id', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->fresh()->lead_owner_id)->toBeNull();
});

test('owning a lead makes it visible to the owner', function () {
    // The owner is accountable for the lead, so it is theirs to see — even
    // when somebody else is the one assigned to work it.
    $owner = leadOwnerEditor();
    $lead = Lead::factory()->create(['lead_owner_id' => $owner->id]);

    expect(Lead::query()->visibleTo($owner)->whereKey($lead->id)->exists())->toBeTrue()
        ->and($owner->can('view', $lead))->toBeTrue();
});

test('a lead neither assigned to nor owned by somebody stays hidden from them', function () {
    $outsider = leadOwnerEditor();
    $lead = Lead::factory()->create(['lead_owner_id' => User::factory()->create()->id]);

    expect(Lead::query()->visibleTo($outsider)->whereKey($lead->id)->exists())->toBeFalse()
        ->and($outsider->can('view', $lead))->toBeFalse();
});

test('a team sees the leads its members own', function () {
    $team = Team::factory()->create();
    $viewer = User::factory()->create(['current_team_id' => $team->id]);
    $viewer->assignRole(Role::query()->create([
        'name' => 'Leads team '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::Team->value,
    ]));
    $viewer = $viewer->fresh();
    $teammate = User::factory()->create(['current_team_id' => $team->id]);
    $lead = Lead::factory()->create(['lead_owner_id' => $teammate->id]);

    expect(Lead::query()->visibleTo($viewer)->whereKey($lead->id)->exists())->toBeTrue();
});

test('an update that says nothing about the owner leaves it alone', function () {
    // Ingestion, chat and Meta updates never carry the key.
    $owner = User::factory()->create();
    $lead = Lead::factory()->create(['lead_owner_id' => $owner->id]);

    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        'first_name' => $lead->first_name,
        'last_name' => $lead->last_name,
        'email' => 'changed@example.com',
    ]));

    expect($lead->fresh()->lead_owner_id)->toBe($owner->id);
});

test('automated captures leave the owner blank', function () {
    $actor = User::factory()->create();

    $lead = app(CreateLeadAction::class)(LeadData::fromArray([
        'first_name' => 'Web', 'last_name' => 'Visitor', 'email' => 'visitor@example.com',
    ]), $actor);

    expect($lead->lead_owner_id)->toBeNull();
});

test('the lead page shows the owner', function () {
    $user = leadOwnerEditor();
    $lead = Lead::factory()->ownedBy($user)->create([
        'lead_owner_id' => User::factory()->create(['name' => 'Karim Ahmed'])->id,
    ]);

    Livewire::actingAs($user)->test(LeadShow::class, ['lead' => $lead])->assertSee('Karim Ahmed');
});

test('the list filters by owner', function () {
    $owner = User::factory()->create();
    Lead::factory()->named('Owned', 'Lead')->create(['lead_owner_id' => $owner->id]);
    Lead::factory()->named('Unowned', 'Lead')->create();

    $query = Lead::query();
    app(FilterApplier::class)->apply($query, FilterGroup::fromArray([
        'match' => 'all',
        'conditions' => [['field' => 'lead_owner_id', 'operator' => FilterOperator::Equals->value, 'value' => (string) $owner->id]],
        'groups' => [],
    ]), LeadFields::filters());

    expect($query->pluck('first_name')->all())->toBe(['Owned']);
});

test('the list shows the owner column', function () {
    $user = leadOwnerEditor();
    Lead::factory()->ownedBy($user)->create(['lead_owner_id' => User::factory()->create(['name' => 'Karim Ahmed'])->id]);

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('setViewMode', 'table')
        ->assertSee('Lead owner')
        ->assertSee('Karim Ahmed');
});
