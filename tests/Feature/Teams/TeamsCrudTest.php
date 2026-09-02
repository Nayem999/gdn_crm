<?php

use App\Livewire\Teams\TeamForm;
use App\Livewire\Teams\TeamsIndex;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function teamAdmin(array $permissions = ['teams.view', 'teams.create', 'teams.update', 'teams.delete']): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission));
    }

    return $user;
}

test('the teams list needs the teams.view permission', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(TeamsIndex::class)->assertForbidden();
});

test('the teams list shows teams with their member counts', function () {
    $this->actingAs(teamAdmin());

    $team = Team::factory()->create(['name' => 'Inside Sales']);
    $team->users()->attach(User::factory()->count(3)->create()->pluck('id'));

    Livewire::test(TeamsIndex::class)
        ->assertSee('Inside Sales')
        ->assertSeeInOrder(['Inside Sales', '3']);
});

test('the teams list nests sub-teams beneath their parent', function () {
    $this->actingAs(teamAdmin());

    $parent = Team::factory()->create(['name' => 'Revenue']);
    $child = Team::factory()->create(['name' => 'Inside Sales', 'parent_id' => $parent->id]);
    Team::factory()->create(['name' => 'Enterprise Sales', 'parent_id' => $child->id]);

    Livewire::test(TeamsIndex::class)
        ->assertViewHas('rows', function ($rows) {
            $byName = $rows->mapWithKeys(fn (array $row) => [$row['team']->name => $row['depth']]);

            return $byName['Revenue'] === 0
                && $byName['Inside Sales'] === 1
                && $byName['Enterprise Sales'] === 2;
        });
});

test('the teams list can be searched, flattening matches', function () {
    $this->actingAs(teamAdmin());

    $parent = Team::factory()->create(['name' => 'Revenue']);
    Team::factory()->create(['name' => 'Inside Sales', 'parent_id' => $parent->id]);

    Livewire::test(TeamsIndex::class)
        ->set('search', 'Inside')
        ->assertSee('Inside Sales')
        ->assertDontSee('Revenue')
        ->call('clearSearch')
        ->assertSet('search', '');
});

test('the teams list shows an empty state', function () {
    $this->actingAs(teamAdmin());

    Livewire::test(TeamsIndex::class)->assertSee('No teams yet.');
});

test('a team can be created with a parent and members', function () {
    $this->actingAs(teamAdmin());

    $parent = Team::factory()->create(['name' => 'Revenue']);
    $members = User::factory()->count(2)->create();

    Livewire::test(TeamForm::class)
        ->set('name', 'Inside Sales')
        ->set('description', 'Desk-based sellers')
        ->set('parentId', (string) $parent->id)
        ->set('memberIds', $members->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.teams'));

    $team = Team::query()->where('name', 'Inside Sales')->sole();

    expect($team->description)->toBe('Desk-based sellers')
        ->and($team->parent_id)->toBe($parent->id)
        ->and($team->users()->pluck('users.id')->sort()->values()->all())
        ->toBe($members->pluck('id')->sort()->values()->all());
});

test('creating a team requires a unique name', function () {
    $this->actingAs(teamAdmin());

    Team::factory()->create(['name' => 'Revenue']);

    Livewire::test(TeamForm::class)
        ->set('name', 'Revenue')
        ->call('save')
        ->assertHasErrors(['name' => 'unique']);
});

test('creating a team requires a name', function () {
    $this->actingAs(teamAdmin());

    Livewire::test(TeamForm::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

test('creating a team needs the teams.create permission', function () {
    $this->actingAs(teamAdmin(['teams.view']));

    Livewire::test(TeamForm::class)->assertForbidden();
});

test('a team can be renamed and re-parented', function () {
    $this->actingAs(teamAdmin());

    $parent = Team::factory()->create(['name' => 'Revenue']);
    $team = Team::factory()->create(['name' => 'Old Name']);

    Livewire::test(TeamForm::class, ['team' => $team])
        ->assertSet('name', 'Old Name')
        ->set('name', 'Inside Sales')
        ->set('parentId', (string) $parent->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->name)->toBe('Inside Sales')
        ->and($team->fresh()->parent_id)->toBe($parent->id);
});

test('a team cannot be made its own parent', function () {
    $this->actingAs(teamAdmin());

    $team = Team::factory()->create();

    Livewire::test(TeamForm::class, ['team' => $team])
        ->set('parentId', (string) $team->id)
        ->call('save')
        ->assertHasErrors('parentId');

    expect($team->fresh()->parent_id)->toBeNull();
});

test('a team cannot be moved beneath its own descendant', function () {
    $this->actingAs(teamAdmin());

    $grandparent = Team::factory()->create(['name' => 'Revenue']);
    $parent = Team::factory()->create(['name' => 'Sales', 'parent_id' => $grandparent->id]);
    $child = Team::factory()->create(['name' => 'Inside Sales', 'parent_id' => $parent->id]);

    Livewire::test(TeamForm::class, ['team' => $grandparent])
        ->set('parentId', (string) $child->id)
        ->call('save')
        ->assertHasErrors('parentId');

    expect($grandparent->fresh()->parent_id)->toBeNull();
});

test('the parent options never offer the team itself or its descendants', function () {
    $this->actingAs(teamAdmin());

    $team = Team::factory()->create(['name' => 'Revenue']);
    $child = Team::factory()->create(['name' => 'Sales', 'parent_id' => $team->id]);
    $grandchild = Team::factory()->create(['name' => 'Inside Sales', 'parent_id' => $child->id]);
    $unrelated = Team::factory()->create(['name' => 'Support']);

    $options = Livewire::test(TeamForm::class, ['team' => $team])->instance()->parentOptions();

    expect(array_keys($options))->toBe([$unrelated->id])
        ->and($options)->not->toHaveKey($child->id)
        ->and($options)->not->toHaveKey($grandchild->id);
});

test('a team can be deleted, promoting its sub-teams to the top level', function () {
    $this->actingAs(teamAdmin());

    $team = Team::factory()->create(['name' => 'Revenue']);
    $child = Team::factory()->create(['name' => 'Sales', 'parent_id' => $team->id]);

    Livewire::test(TeamsIndex::class)
        ->call('delete', $team->id)
        ->assertHasNoErrors()
        ->assertDispatched('team-deleted');

    expect(Team::query()->find($team->id))->toBeNull()
        ->and($child->fresh()->parent_id)->toBeNull();
});

test('deleting a team removes its memberships and clears it as an active team', function () {
    $this->actingAs(teamAdmin());

    $team = Team::factory()->create();
    $member = User::factory()->create(['current_team_id' => $team->id]);
    $team->users()->attach($member);

    Livewire::test(TeamsIndex::class)->call('delete', $team->id);

    expect($member->fresh()->current_team_id)->toBeNull()
        ->and($member->fresh()->teams()->count())->toBe(0)
        // The member themselves is untouched.
        ->and(User::query()->find($member->id))->not->toBeNull();
});

test('deleting a team needs the teams.delete permission', function () {
    $this->actingAs(teamAdmin(['teams.view']));

    $team = Team::factory()->create();

    Livewire::test(TeamsIndex::class)
        ->call('delete', $team->id)
        ->assertForbidden();

    expect(Team::query()->find($team->id))->not->toBeNull();
});

test('the hierarchy helpers report ancestors, descendants and depth', function () {
    $root = Team::factory()->create();
    $mid = Team::factory()->create(['parent_id' => $root->id]);
    $leaf = Team::factory()->create(['parent_id' => $mid->id]);

    expect($leaf->ancestors()->pluck('id')->all())->toBe([$mid->id, $root->id])
        ->and($root->descendants()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mid->id, $leaf->id])->sort()->values()->all())
        ->and($leaf->depth())->toBe(2)
        ->and($root->depth())->toBe(0)
        ->and($leaf->isDescendantOf($root))->toBeTrue()
        ->and($root->isDescendantOf($leaf))->toBeFalse();
});

test('the ancestor walk terminates even if the stored data contains a cycle', function () {
    $a = Team::factory()->create();
    $b = Team::factory()->create(['parent_id' => $a->id]);

    // Force a cycle straight into the database, bypassing the action's guard.
    $a->forceFill(['parent_id' => $b->id])->save();

    expect($a->fresh()->ancestors()->count())->toBeLessThanOrEqual(2);
});
