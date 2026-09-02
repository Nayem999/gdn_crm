<?php

use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Teams\Actions\SyncTeamMembersAction;
use App\Livewire\Teams\TeamForm;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function membershipAdmin(): User
{
    $user = User::factory()->create();

    foreach (['teams.view', 'teams.create', 'teams.update', 'teams.delete'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission));
    }

    return $user;
}

/**
 * A stand-in owned record, so team scoping can be asserted before the real
 * business models arrive in Phase 2.
 */
function ownedRecordModel(): Model
{
    if (! Schema::hasTable('team_scope_records')) {
        Schema::create('team_scope_records', function ($table) {
            $table->id();
            $table->foreignId('owner_id');
            $table->timestamps();
        });
    }

    return new class extends Model
    {
        use ScopesByAccessLevel;

        protected $table = 'team_scope_records';

        protected $fillable = ['owner_id'];
    };
}

function userWithTeamAccess(?Team $team): User
{
    $user = User::factory()->create(['current_team_id' => $team?->id]);
    $user->assignRole(Role::create([
        'name' => 'team-scoped-'.uniqid(),
        'data_access_level' => DataAccessLevel::Team->value,
    ]));

    return $user;
}

test('members can be assigned to a team through the form', function () {
    $this->actingAs(membershipAdmin());

    $team = Team::factory()->create();
    $members = User::factory()->count(3)->create();

    Livewire::test(TeamForm::class, ['team' => $team])
        ->set('memberIds', $members->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->users()->pluck('users.id')->sort()->values()->all())
        ->toBe($members->pluck('id')->sort()->values()->all());
});

test('the form loads the current membership for editing', function () {
    $this->actingAs(membershipAdmin());

    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->users()->attach($member);

    Livewire::test(TeamForm::class, ['team' => $team])
        ->assertSet('memberIds', [(string) $member->id]);
});

test('members can be removed by leaving them out of the selection', function () {
    $this->actingAs(membershipAdmin());

    $team = Team::factory()->create();
    $staying = User::factory()->create();
    $leaving = User::factory()->create();
    $team->users()->attach([$staying->id, $leaving->id]);

    Livewire::test(TeamForm::class, ['team' => $team])
        ->set('memberIds', [(string) $staying->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh()->users()->pluck('users.id')->all())->toBe([$staying->id]);
});

test('a new member with no active team gets this one', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => null]);

    app(SyncTeamMembersAction::class)($team, [$user->id]);

    expect($user->fresh()->current_team_id)->toBe($team->id);
});

test('a new member who already has an active team keeps it', function () {
    $existing = Team::factory()->create();
    $other = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $existing->id]);

    app(SyncTeamMembersAction::class)($other, [$user->id]);

    expect($user->fresh()->current_team_id)->toBe($existing->id)
        ->and($user->fresh()->teams()->pluck('teams.id')->all())->toBe([$other->id]);
});

test('a removed member loses this team as their active team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->users()->attach($user);

    app(SyncTeamMembersAction::class)($team, []);

    expect($user->fresh()->current_team_id)->toBeNull()
        ->and($user->fresh()->teams()->count())->toBe(0);
});

test('removing a member does not disturb another team they are active in', function () {
    $active = Team::factory()->create();
    $other = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $active->id]);
    $other->users()->attach($user);

    app(SyncTeamMembersAction::class)($other, []);

    expect($user->fresh()->current_team_id)->toBe($active->id);
});

test('a user can belong to several teams at once', function () {
    $this->actingAs(membershipAdmin());

    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $user = User::factory()->create();

    app(SyncTeamMembersAction::class)($first, [$user->id]);
    app(SyncTeamMembersAction::class)($second, [$user->id]);

    expect($user->fresh()->teams()->pluck('teams.id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        // The first team assigned remains the active one.
        ->and($user->fresh()->current_team_id)->toBe($first->id);
});

test('team level access sees records owned by everyone active in the same team', function () {
    $model = ownedRecordModel();

    $team = Team::factory()->create();
    $me = userWithTeamAccess($team);
    $teammate = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create(['current_team_id' => Team::factory()->create()->id]);

    app(SyncTeamMembersAction::class)($team, [$me->id, $teammate->id]);

    $mine = $model->newQuery()->create(['owner_id' => $me->id]);
    $theirs = $model->newQuery()->create(['owner_id' => $teammate->id]);
    $model->newQuery()->create(['owner_id' => $outsider->id]);

    expect($model->newQuery()->visibleTo($me)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mine->id, $theirs->id])->sort()->values()->all());
});

test('dropping someone from a team immediately narrows what they can see', function () {
    $model = ownedRecordModel();

    $team = Team::factory()->create();
    $me = userWithTeamAccess($team);
    $teammate = User::factory()->create(['current_team_id' => $team->id]);

    app(SyncTeamMembersAction::class)($team, [$me->id, $teammate->id]);

    $mine = $model->newQuery()->create(['owner_id' => $me->id]);
    $model->newQuery()->create(['owner_id' => $teammate->id]);

    expect($model->newQuery()->visibleTo($me)->count())->toBe(2);

    // Removing me from the team clears my active team, so team access falls
    // back to my own records only rather than leaving the old team visible.
    app(SyncTeamMembersAction::class)($team, [$teammate->id]);

    $visible = $model->newQuery()->visibleTo($me->fresh())->get();

    expect($visible->pluck('id')->all())->toBe([$mine->id]);
});

test('deleting a team narrows its members back to their own records', function () {
    $model = ownedRecordModel();

    $team = Team::factory()->create();
    $me = userWithTeamAccess($team);
    $teammate = User::factory()->create(['current_team_id' => $team->id]);

    $mine = $model->newQuery()->create(['owner_id' => $me->id]);
    $model->newQuery()->create(['owner_id' => $teammate->id]);

    $team->delete();

    expect($model->newQuery()->visibleTo($me->fresh())->pluck('id')->all())->toBe([$mine->id]);
});
