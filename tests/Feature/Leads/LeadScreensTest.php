<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Actions\SyncLeadAssigneesAction;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadExportSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Models\UserViewPreference;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Leads\LeadsIndex;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * @param  array<int, string>  $permissions
 */
function leadUser(array $permissions = ['leads.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function leadAdmin(): User
{
    return leadUser([
        'leads.view', 'leads.create', 'leads.update', 'leads.assign', 'leads.delete', 'leads.export',
    ]);
}

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Access --------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $this->get(route('leads.index'))->assertRedirect(route('login'));
});

test('the list needs the leads.view permission', function () {
    $this->actingAs(User::factory()->create())->get(route('leads.index'))->assertForbidden();

    $this->actingAs(leadUser())->get(route('leads.index'))->assertOk()->assertSee('Leads');
});

test('capturing needs the create permission', function () {
    $this->actingAs(leadUser())->get(route('leads.create'))->assertForbidden();

    $this->actingAs(leadUser(['leads.view', 'leads.create']))
        ->get(route('leads.create'))
        ->assertOk()
        ->assertSee('Capture lead');
});

test('a record outside the access level cannot be reached by guessing its id', function () {
    $user = leadUser();
    $other = Lead::factory()->create();

    $this->actingAs($user)->get(route('leads.show', $other))->assertForbidden();

    expect($user->can('view', $other))->toBeFalse();
});

test('the list only shows what the viewer may see', function () {
    $user = leadUser();

    Lead::factory()->ownedBy($user)->named('Mine', 'Lead')->create();
    Lead::factory()->named('Hidden', 'Lead')->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->assertSee('Mine Lead')
        ->assertDontSee('Hidden Lead');
});

// -- Access level --------------------------------------------------------------

/**
 * @param  array<string, mixed>  $attributes
 */
function leadUserWithLevel(DataAccessLevel $level, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    $role = Role::query()->create([
        'name' => 'Leads '.$level->value.' '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level->value,
    ]);

    $user->assignRole($role);

    return $user->fresh();
}

test('own access sees only their own leads', function () {
    $user = leadUserWithLevel(DataAccessLevel::Own);

    Lead::factory()->ownedBy($user)->named('Mine', 'Lead')->create();
    Lead::factory()->named('Their', 'Lead')->create();

    expect(Lead::query()->visibleTo($user)->pluck('first_name')->all())->toBe(['Mine']);
});

test('team access sees the whole active team', function () {
    $team = Team::factory()->create();

    $user = leadUserWithLevel(DataAccessLevel::Team, ['current_team_id' => $team->id]);
    $colleague = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create();

    Lead::factory()->ownedBy($user)->named('Mine', 'L')->create();
    Lead::factory()->ownedBy($colleague)->named('Colleague', 'L')->create();
    Lead::factory()->ownedBy($outsider)->named('Outsider', 'L')->create();

    expect(Lead::query()->visibleTo($user)->pluck('first_name')->sort()->values()->all())
        ->toBe(['Colleague', 'Mine']);
});

test('all access sees everything', function () {
    $user = leadUserWithLevel(DataAccessLevel::All);

    Lead::factory()->ownedBy($user)->create();
    Lead::factory()->count(2)->create();

    expect(Lead::query()->visibleTo($user)->count())->toBe(3);
});

// -- Capture, update, delete ---------------------------------------------------

test('a lead can be captured', function () {
    $user = leadAdmin();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('company_name', 'Acme Corporation')
        ->set('email', 'dana@acme.test')
        ->set('source', LeadSource::Referral->value)
        ->set('estimated_value', '25000.00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $lead = Lead::query()->firstOrFail();

    expect($lead->fullName())->toBe('Dana Scully')
        ->and($lead->source())->toBe(LeadSource::Referral)
        ->and($lead->estimated_value)->toBe('25000.00')
        // Always New on capture.
        ->and($lead->status())->toBe(LeadStatus::New);
});

test('the capture form has no status field to set', function () {
    $rendered = Livewire::actingAs(leadAdmin())->test(LeadForm::class)->html();

    expect($rendered)->toContain('New leads start as')
        ->and($rendered)->not->toContain('id="status"');
});

test('a lead needs an email address or a phone number', function () {
    Livewire::actingAs(leadAdmin())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->call('save')
        ->assertHasErrors(['email', 'phone']);

    expect(Lead::query()->count())->toBe(0);

    // Either one on its own is enough.
    Livewire::actingAs(leadAdmin())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('phone', '0113 496 0000')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe(1);
});

test('capturing validates the required and bounded fields', function () {
    Livewire::actingAs(leadAdmin())
        ->test(LeadForm::class)
        ->set('first_name', '')
        ->set('last_name', '')
        ->set('email', 'not-an-email')
        ->set('source', 'time-travel')
        ->set('estimated_value', '-5')
        ->call('save')
        ->assertHasErrors(['first_name', 'last_name', 'email', 'source', 'estimated_value']);
});

test('a value beyond what the column holds is refused rather than truncated', function () {
    Livewire::actingAs(leadAdmin())
        ->test(LeadForm::class)
        ->set('first_name', 'Big')
        ->set('last_name', 'Deal')
        ->set('phone', '0113 496 0000')
        ->set('estimated_value', '99999999999999.99')
        ->call('save')
        ->assertHasErrors(['estimated_value']);
});

test('a lead can be edited without its status moving', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->named('Before', 'Name')->create();

    Livewire::actingAs($user)
        ->test(LeadForm::class, ['lead' => $lead])
        ->assertSet('first_name', 'Before')
        ->set('first_name', 'After')
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->fresh()->first_name)->toBe('After')
        ->and($lead->fresh()->status())->toBe(LeadStatus::Qualified);
});

test('a lead can be removed from its own page', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('delete')
        ->assertRedirect(route('leads.index'));

    expect($lead->fresh()->trashed())->toBeTrue();
});

test('removing needs the delete permission', function () {
    $user = leadUser(['leads.view', 'leads.update']);
    $lead = Lead::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('delete')
        ->assertForbidden();

    expect($lead->fresh()->trashed())->toBeFalse();
});

test('a bulk removal only touches records the viewer may delete', function () {
    $user = leadAdmin();
    $mine = Lead::factory()->ownedBy($user)->create();
    $theirs = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->set('selected', [$mine->id, $theirs->id])
        ->call('deleteSelected');

    expect($mine->fresh()->trashed())->toBeTrue()
        ->and($theirs->fresh()->trashed())->toBeFalse();
});

// -- Status from the UI --------------------------------------------------------

test('the detail page offers only the moves the status allows', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    $component = Livewire::actingAs($user)->test(LeadShow::class, ['lead' => $lead]);

    expect($component->instance()->availableTransitions())
        ->toBe([LeadStatus::Contacted, LeadStatus::Nurturing, LeadStatus::Unqualified]);

    $component->assertSee('Contacted')
        ->assertSee('Nurturing')
        ->assertSee('Unqualified')
        // Never offered: conversion is task 2.6's job.
        ->assertDontSee("changeStatus('converted')", false);
});

test('a status can be moved on from the detail page', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('changeStatus', LeadStatus::Contacted->value)
        ->assertDispatched('lead-updated');

    expect($lead->fresh()->status())->toBe(LeadStatus::Contacted);
});

test('a forbidden move from the detail page says why and changes nothing', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('changeStatus', LeadStatus::Qualified->value)
        ->assertDispatched('notify');

    expect($lead->fresh()->status())->toBe(LeadStatus::New);
});

test('an unknown status from a tampered payload changes nothing', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    Livewire::actingAs($user)->test(LeadShow::class, ['lead' => $lead])->call('changeStatus', 'time-travel');

    expect($lead->fresh()->status())->toBe(LeadStatus::New);
});

test('moving a status needs the update permission', function () {
    $user = leadUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('changeStatus', LeadStatus::Contacted->value)
        ->assertForbidden();

    expect($lead->fresh()->status())->toBe(LeadStatus::New);
});

test('a converted lead is shown as immovable', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Converted)->create();

    // Task 2.6 replaced the "it stays where it is" copy with a panel naming
    // what the lead became. The point of this test is the same: a converted
    // lead is offered no moves at all.
    $component = Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('Converted')
        ->assertDontSee('Move this lead on');

    expect($component->instance()->availableTransitions())->toBe([]);
});

// -- Assignment ----------------------------------------------------------------

test('a lead can gain another assignee', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->create();
    $colleague = User::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->set('newAssigneeId', (string) $colleague->id)
        ->call('addAssignee')
        ->assertDispatched('lead-updated');

    expect(leadAssigneeIds($lead->fresh()))->toContain($user->id, $colleague->id);
});

test('assigning needs its own permission, separate from editing', function () {
    $user = leadUser(['leads.view', 'leads.update']);
    $lead = Lead::factory()->ownedBy($user)->create();
    $colleague = User::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->set('newAssigneeId', (string) $colleague->id)
        ->call('addAssignee')
        ->assertForbidden();

    expect(leadAssigneeIds($lead->fresh()))->toBe([$user->id]);
});

test('somebody already on the lead does not appear in the add list', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->create();

    $component = Livewire::actingAs($user)->test(LeadShow::class, ['lead' => $lead]);

    expect(array_keys($component->instance()->assignableOptions()))->not->toContain($user->id);
});

test('adding somebody who does not exist is refused', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->set('newAssigneeId', '999999')
        ->call('addAssignee')
        ->assertHasErrors(['newAssigneeId']);
});

test('the last assignee cannot be removed', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('removeAssignee', $user->id)
        ->assertDispatched('notify');

    expect(leadAssigneeIds($lead->fresh()))->toBe([$user->id]);
});

test('an assignee can be removed when somebody else is still on the lead', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->create();
    $colleague = User::factory()->create();

    app(SyncLeadAssigneesAction::class)->add($lead, $colleague);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('removeAssignee', $colleague->id)
        ->assertDispatched('lead-updated');

    expect(leadAssigneeIds($lead->fresh()))->toBe([$user->id]);
});

// -- Views ---------------------------------------------------------------------

test('every view mode renders with data', function (ViewMode $mode) {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->named('Dana', 'Scully')->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('Dana Scully');
})->with(ViewMode::cases());

test('every view mode renders when there is nothing', function (ViewMode $mode) {
    Livewire::actingAs(leadUser())
        ->test(LeadsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('No leads yet');
})->with(ViewMode::cases());

test('the view mode persists per user', function () {
    $user = leadUser();

    Livewire::actingAs($user)->test(LeadsIndex::class)->call('setViewMode', ViewMode::Kanban->value);

    expect(UserViewPreference::lookup($user, 'leads')->view_mode)->toBe(ViewMode::Kanban->value);

    Livewire::actingAs($user)->test(LeadsIndex::class)->assertSet('viewMode', ViewMode::Kanban->value);
});

test('a hidden column persists and leaves the table', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->assertSee("wire:click=\"sort('source')\"", false)
        ->call('toggleColumn', 'source')
        ->assertDontSee("wire:click=\"sort('source')\"", false);

    expect(UserViewPreference::lookup($user, 'leads')->columns)->not->toContain('source');
});

// -- The pipeline board --------------------------------------------------------

test('the board is grouped by status, one column per pipeline stage', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->status(LeadStatus::Contacted)->named('Dana', 'Scully')->create();

    $component = Livewire::actingAs($user)->test(LeadsIndex::class)->call('setViewMode', ViewMode::Kanban->value);

    expect($component->instance()->dataViewKanbanField())->toBe('status')
        ->and($component->instance()->dataViewKanbanColumns())->toHaveCount(count(LeadStatus::pipeline()));

    $component->assertSee('Dana Scully');

    foreach (LeadStatus::pipeline() as $status) {
        $component->assertSee($status->label());
    }
});

test('each column counts and totals the whole filtered set, not just a page', function () {
    $user = leadUser();

    // More than one page of rows, so a board that grouped the current page
    // would report the wrong counts.
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->worth('1000.00')->count(30)->create();
    Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->worth('5000.00')->count(3)->create();

    $totals = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->instance()
        ->kanbanTotals();

    expect($totals[LeadStatus::New->value]['count'])->toBe(30)
        ->and($totals[LeadStatus::New->value]['sum'])->toBe(30000.0)
        ->and($totals[LeadStatus::Qualified->value]['count'])->toBe(3)
        ->and($totals[LeadStatus::Qualified->value]['sum'])->toBe(15000.0);
});

test('the totals respect the active filter', function () {
    $user = leadUser();

    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->source(LeadSource::Referral)->worth('1000.00')->count(2)->create();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->source(LeadSource::ColdCall)->worth('1000.00')->count(5)->create();

    $totals = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'source')
        ->set('filters.conditions.0.operator', FilterOperator::Equals->value)
        ->set('filters.conditions.0.value', LeadSource::Referral->value)
        ->instance()
        ->kanbanTotals();

    expect($totals[LeadStatus::New->value]['count'])->toBe(2)
        ->and($totals[LeadStatus::New->value]['sum'])->toBe(2000.0);
});

test('a column loads a batch at a time with more available', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->count(LeadsIndex::KANBAN_PAGE + 5)->create();

    $component = Livewire::actingAs($user)->test(LeadsIndex::class)->call('setViewMode', ViewMode::Kanban->value);
    $board = $component->instance();

    expect($board->kanbanCards(LeadStatus::New->value))->toHaveCount(LeadsIndex::KANBAN_PAGE)
        ->and($board->hasMoreKanbanCards(LeadStatus::New->value))->toBeTrue();

    $component->call('loadMoreKanban', LeadStatus::New->value);

    expect($component->instance()->kanbanCards(LeadStatus::New->value))
        ->toHaveCount(LeadsIndex::KANBAN_PAGE + 5)
        ->and($component->instance()->hasMoreKanbanCards(LeadStatus::New->value))->toBeFalse();
});

test('loading more in one column leaves the others alone', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->count(LeadsIndex::KANBAN_PAGE + 5)->create();
    Lead::factory()->ownedBy($user)->status(LeadStatus::Contacted)->count(LeadsIndex::KANBAN_PAGE + 5)->create();

    $component = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->call('loadMoreKanban', LeadStatus::New->value);

    expect($component->instance()->kanbanLimitFor(LeadStatus::New->value))
        ->toBe(LeadsIndex::KANBAN_PAGE * 2)
        ->and($component->instance()->kanbanLimitFor(LeadStatus::Contacted->value))
        ->toBe(LeadsIndex::KANBAN_PAGE);
});

test('load more for a column the board does not have is ignored', function () {
    $component = Livewire::actingAs(leadUser())
        ->test(LeadsIndex::class)
        ->call('loadMoreKanban', 'time-travel');

    expect($component->get('kanbanLimits'))->toBe([]);
});

test('the board never shows a card the viewer may not see', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->named('Mine', 'Lead')->create();
    Lead::factory()->status(LeadStatus::New)->named('Hidden', 'Lead')->create();

    $board = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->instance();

    expect($board->kanbanCards(LeadStatus::New->value)->pluck('first_name')->all())->toBe(['Mine'])
        ->and($board->kanbanTotals()[LeadStatus::New->value]['count'])->toBe(1);
});

test('a drag applies an allowed status move', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('moveCard', $lead->id, LeadStatus::Contacted->value)
        ->assertDispatched('lead-updated');

    expect($lead->fresh()->status())->toBe(LeadStatus::Contacted);
});

test('a drag that breaks the transition rules is refused with a reason', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('moveCard', $lead->id, LeadStatus::Qualified->value)
        ->assertDispatched('notify');

    expect($lead->fresh()->status())->toBe(LeadStatus::New);
});

test('a drag into converted is refused', function () {
    $user = leadAdmin();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('moveCard', $lead->id, LeadStatus::Converted->value)
        ->assertDispatched('notify');

    expect($lead->fresh()->status())->toBe(LeadStatus::Qualified);
});

test('a drag of a lead the viewer cannot see does nothing', function () {
    $user = leadAdmin();
    $hidden = Lead::factory()->status(LeadStatus::New)->create();

    Livewire::actingAs($user)->test(LeadsIndex::class)->call('moveCard', $hidden->id, LeadStatus::Contacted->value);

    expect($hidden->fresh()->status())->toBe(LeadStatus::New);
});

test('dragging needs the update permission', function () {
    $user = leadUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::New)->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('moveCard', $lead->id, LeadStatus::Contacted->value)
        ->assertForbidden();

    expect($lead->fresh()->status())->toBe(LeadStatus::New);
});

// -- Search, filters, sorting --------------------------------------------------

test('searching narrows the list, including the full name', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->named('Dana', 'Scully')->create();
    Lead::factory()->ownedBy($user)->named('Fox', 'Mulder')->create();

    $component = Livewire::actingAs($user)->test(LeadsIndex::class);

    $component->set('search', 'Scully')->assertSee('Dana Scully')->assertDontSee('Fox Mulder');
    $component->set('search', 'Fox Mulder')->assertSee('Fox Mulder')->assertDontSee('Dana Scully');
});

test('a status filter narrows the list', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->named('Is', 'Qualified')->create();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->named('Is', 'New')->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'status')
        ->set('filters.conditions.0.operator', FilterOperator::Equals->value)
        ->set('filters.conditions.0.value', LeadStatus::Qualified->value)
        ->assertSee('Is Qualified')
        ->assertDontSee('Is New');
});

test('the quick filter chips narrow the list and toggle off again', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-01 09:00:00'));

    $user = leadUser();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->named('Still', 'Open')->create();
    Lead::factory()->ownedBy($user)->status(LeadStatus::Converted)->named('Already', 'Done')->create();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->stalledFor(30)->named('Long', 'Forgotten')->create();

    $component = Livewire::actingAs($user)->test(LeadsIndex::class);

    $component->call('setQuickFilter', 'open');
    expect($component->instance()->dataViewBaseQuery()->count())->toBe(2);

    $component->call('setQuickFilter', 'open');
    expect($component->get('quickFilter'))->toBe('')
        ->and($component->instance()->dataViewBaseQuery()->count())->toBe(3);

    $component->call('setQuickFilter', 'stalled');
    expect($component->instance()->dataViewBaseQuery()->pluck('first_name')->all())->toBe(['Long']);

    $component->call('setQuickFilter', 'nonsense');
    expect($component->get('quickFilter'))->toBe('');
});

test('sorting the name column orders by surname', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->named('Zoe', 'Adams')->create();
    Lead::factory()->ownedBy($user)->named('Adam', 'Zeta')->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('sort', 'name')
        ->assertSeeInOrder(['Zoe Adams', 'Adam Zeta']);
});

// -- Export --------------------------------------------------------------------

test('exporting needs the export permission', function () {
    expect(Livewire::actingAs(leadUser())->test(LeadsIndex::class)->instance()->canExport())->toBeFalse()
        ->and(Livewire::actingAs(leadAdmin())->test(LeadsIndex::class)->instance()->canExport())->toBeTrue();
});

test('an export downloads and respects the active filter', function () {
    Excel::fake();
    Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

    $user = leadAdmin();
    Lead::factory()->ownedBy($user)->named('Dana', 'Scully')->create();

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->set('search', 'Scully')
        ->call('export', ExportFormat::Csv->value)
        ->assertOk();

    Excel::assertDownloaded('leads-2026-01-01-090000.csv');
});

test('an export can never contain rows the person could not see', function () {
    $user = leadAdmin();
    Lead::factory()->ownedBy($user)->named('Mine', 'Lead')->create();
    Lead::factory()->named('Hidden', 'Lead')->create();

    $request = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    expect(app(LeadExportSource::class)->exportQuery($request)->pluck('first_name')->all())->toBe(['Mine']);
});

test('the export writes readable values rather than stored ones', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-15 09:00:00'));

    $user = leadAdmin();
    Lead::factory()->ownedBy($user)
        ->status(LeadStatus::Qualified)
        ->source(LeadSource::Referral)
        ->stalledFor(4)
        ->named('Dana', 'Scully')
        ->create();

    $request = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    $source = app(LeadExportSource::class);
    $row = $source->exportRow($source->exportQuery($request)->firstOrFail(), $request);

    expect($row)->toContain('Dana Scully')
        ->and($row)->toContain(LeadStatus::Qualified->label())
        ->and($row)->toContain(LeadSource::Referral->label())
        ->and($row)->toContain($user->name)
        ->and($row)->toContain(4);
});

test('every select on the capture form uses the shared component', function () {
    $rendered = Livewire::actingAs(leadAdmin())->test(LeadForm::class)->html();

    // Four dropdowns: source, campaign, lead owner and the first assignee.
    expect(substr_count($rendered, 'tomSelectField('))->toBe(4)
        ->and(substr_count($rendered, '<select'))->toBe(4);
});
