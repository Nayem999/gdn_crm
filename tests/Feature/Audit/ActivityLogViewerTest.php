<?php

use App\Livewire\Audit\ActivityLogIndex;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

function auditViewer(array $permissions = ['audit.view']): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission));
    }

    return $user;
}

test('the audit log needs the audit.view permission', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ActivityLogIndex::class)->assertForbidden();
});

test('guests cannot reach the audit log', function () {
    $this->get(route('settings.audit'))->assertRedirect(route('login'));
});

test('the audit log lists entries with who did what', function () {
    $actor = auditViewer();
    $this->actingAs($actor);

    Team::factory()->create(['name' => 'Inside Sales']);

    Livewire::test(ActivityLogIndex::class)
        ->assertSee('Team was created')
        ->assertSee($actor->name)
        ->assertSee('Created');
});

test('the audit log shows an empty state when nothing is recorded', function () {
    $this->actingAs(auditViewer());

    // The viewer's own sign-in writes nothing, but creating the user did.
    Activity::query()->delete();

    Livewire::test(ActivityLogIndex::class)->assertSee('Nothing recorded yet.');
});

test('the audit log can be filtered by action', function () {
    $this->actingAs(auditViewer());

    $team = Team::factory()->create(['name' => 'Filterable Team']);
    $team->update(['name' => 'Renamed Team']);

    Livewire::test(ActivityLogIndex::class)
        ->set('event', 'deleted')
        ->assertViewHas('activities', fn ($activities) => $activities->total() === 0)
        ->set('event', 'updated')
        ->assertViewHas('activities', fn ($activities) => $activities->total() === 1
            && $activities->first()->event === 'updated');
});

test('the audit log can be filtered by record type', function () {
    $this->actingAs(auditViewer());

    Team::factory()->create();

    Livewire::test(ActivityLogIndex::class)
        ->set('subjectType', Team::class)
        ->assertViewHas('activities', fn ($activities) => $activities->total() > 0
            && $activities->every(fn ($activity) => $activity->subject_type === Team::class))
        ->set('subjectType', User::class)
        ->assertViewHas('activities', fn ($activities) => $activities->every(
            fn ($activity) => $activity->subject_type === User::class
        ));
});

test('the audit log can be searched by the person who made the change', function () {
    $actor = auditViewer();
    $this->actingAs($actor);

    Team::factory()->create();

    Livewire::test(ActivityLogIndex::class)
        ->set('search', $actor->email)
        ->assertViewHas('activities', fn ($activities) => $activities->total() > 0)
        ->set('search', 'somebody-else@example.com')
        ->assertViewHas('activities', fn ($activities) => $activities->total() === 0);
});

test('the audit log can be searched by description', function () {
    $this->actingAs(auditViewer());

    Team::factory()->create();

    Livewire::test(ActivityLogIndex::class)
        ->set('search', 'Team was created')
        ->assertViewHas('activities', fn ($activities) => $activities->total() === 1);
});

test('filters can be cleared in one go', function () {
    $this->actingAs(auditViewer());

    Team::factory()->create();

    $component = Livewire::test(ActivityLogIndex::class)
        ->set('search', 'nothing-matches-this')
        ->set('event', 'deleted');

    expect($component->instance()->hasFilters())->toBeTrue();

    $component->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('event', '')
        ->assertSet('subjectType', '');

    expect($component->instance()->hasFilters())->toBeFalse();
});

test('only actions actually present are offered as filter options', function () {
    $this->actingAs(auditViewer());

    Activity::query()->delete();

    $team = Team::factory()->create();
    $team->update(['name' => 'Renamed']);

    $options = Livewire::test(ActivityLogIndex::class)->instance()->eventOptions();

    expect(array_keys($options))->toEqualCanonicalizing(['created', 'updated'])
        ->and($options['created'])->toBe('Created');
});

test('record type options are shown by their short name', function () {
    $this->actingAs(auditViewer());

    Activity::query()->delete();
    Team::factory()->create();

    $options = Livewire::test(ActivityLogIndex::class)->instance()->subjectTypeOptions();

    expect($options)->toHaveKey(Team::class)
        ->and($options[Team::class])->toBe('Team');
});

test('an entry can be expanded to show what changed', function () {
    $this->actingAs(auditViewer());

    $team = Team::factory()->create(['name' => 'Before']);
    $team->update(['name' => 'After']);

    $entry = Activity::query()->where('event', 'updated')->sole();

    $component = Livewire::test(ActivityLogIndex::class);

    expect($component->instance()->isExpanded($entry->id))->toBeFalse();

    $component->call('toggle', $entry->id)
        ->assertSee('Before')
        ->assertSee('After');

    expect($component->instance()->isExpanded($entry->id))->toBeTrue();

    $component->call('toggle', $entry->id);

    expect($component->instance()->isExpanded($entry->id))->toBeFalse();
});

test('the change list pairs each field with its before and after value', function () {
    $this->actingAs(auditViewer());

    $team = Team::factory()->create(['name' => 'Before', 'description' => null]);
    $team->update(['name' => 'After', 'description' => 'Now described']);

    $entry = Activity::query()->where('event', 'updated')->sole();

    $changes = Livewire::test(ActivityLogIndex::class)->instance()->changesFor($entry);

    expect($changes)->toHaveKeys(['name', 'description'])
        ->and($changes['name']['old'])->toBe('Before')
        ->and($changes['name']['new'])->toBe('After')
        ->and($changes['description']['old'])->toBeNull()
        ->and($changes['description']['new'])->toBe('Now described');
});

test('values are rendered readably, including lists and nulls', function () {
    $this->actingAs(auditViewer());

    $component = Livewire::test(ActivityLogIndex::class)->instance();

    expect($component->formatValue(null))->toBe('—')
        ->and($component->formatValue([]))->toBe('—')
        ->and($component->formatValue(''))->toBe('—')
        ->and($component->formatValue(true))->toBe('true')
        ->and($component->formatValue(['users.view', 'teams.view']))->toBe('users.view, teams.view')
        ->and($component->formatValue('plain'))->toBe('plain');
});

test('the audit log paginates', function () {
    $this->actingAs(auditViewer());

    Team::factory()->count(30)->create();

    Livewire::test(ActivityLogIndex::class)
        ->set('perPage', 25)
        ->assertViewHas('activities', fn ($activities) => $activities->count() === 25 && $activities->total() > 25);
});

test('the newest entries come first', function () {
    $this->actingAs(auditViewer());

    Activity::query()->delete();

    Team::factory()->create(['name' => 'First']);
    Team::factory()->create(['name' => 'Second']);

    Livewire::test(ActivityLogIndex::class)
        ->assertViewHas('activities', function ($activities) {
            $ids = $activities->pluck('id')->all();

            return $ids === collect($ids)->sortDesc()->values()->all();
        });
});
