<?php

use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

function makeFixtureRecordModel(): Model
{
    if (! Schema::hasTable('fixture_records')) {
        Schema::create('fixture_records', function ($table) {
            $table->id();
            $table->foreignId('owner_id');
            $table->timestamps();
        });
    }

    return new class extends Model
    {
        use ScopesByAccessLevel;

        protected $table = 'fixture_records';

        protected $fillable = ['owner_id'];
    };
}

function makeUserWithAccessLevel(?DataAccessLevel $level, ?Team $team = null): User
{
    $user = User::factory()->create(['current_team_id' => $team?->id]);

    if ($level !== null) {
        $role = Role::create(['name' => 'role-'.$level->value.'-'.uniqid(), 'data_access_level' => $level->value]);
        $user->assignRole($role);
    }

    return $user;
}

test('a user with own access sees only their own records', function () {
    $model = makeFixtureRecordModel();

    $owner = makeUserWithAccessLevel(DataAccessLevel::Own);
    $other = User::factory()->create();

    $mine = $model->newQuery()->create(['owner_id' => $owner->id]);
    $model->newQuery()->create(['owner_id' => $other->id]);

    $visible = $model->newQuery()->visibleTo($owner)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->id)->toBe($mine->id);
});

test('a user with team access sees records owned by teammates', function () {
    $model = makeFixtureRecordModel();

    $team = Team::factory()->create();
    $me = makeUserWithAccessLevel(DataAccessLevel::Team, $team);
    $teammate = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create();

    $mine = $model->newQuery()->create(['owner_id' => $me->id]);
    $teammateRecord = $model->newQuery()->create(['owner_id' => $teammate->id]);
    $model->newQuery()->create(['owner_id' => $outsider->id]);

    $visible = $model->newQuery()->visibleTo($me)->get();

    expect($visible->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mine->id, $teammateRecord->id])->sort()->values()->all());
});

test('a team-level user with no team falls back to seeing only their own records', function () {
    $model = makeFixtureRecordModel();

    $me = makeUserWithAccessLevel(DataAccessLevel::Team, team: null);
    $otherTeamless = User::factory()->create(['current_team_id' => null]);

    $mine = $model->newQuery()->create(['owner_id' => $me->id]);
    $model->newQuery()->create(['owner_id' => $otherTeamless->id]);

    $visible = $model->newQuery()->visibleTo($me)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->id)->toBe($mine->id);
});

test('a user with all access sees every record', function () {
    $model = makeFixtureRecordModel();

    $admin = makeUserWithAccessLevel(DataAccessLevel::All);
    $others = User::factory()->count(3)->create();

    foreach ($others as $other) {
        $model->newQuery()->create(['owner_id' => $other->id]);
    }
    $model->newQuery()->create(['owner_id' => $admin->id]);

    $visible = $model->newQuery()->visibleTo($admin)->get();

    expect($visible)->toHaveCount(4);
});

test('the broadest access level across multiple roles wins', function () {
    $model = makeFixtureRecordModel();

    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'restrictive', 'data_access_level' => DataAccessLevel::Own->value]));
    $user->assignRole(Role::create(['name' => 'permissive', 'data_access_level' => DataAccessLevel::All->value]));

    expect(ScopesByAccessLevelDummy::resolveAccessLevelFor($user))->toBe(DataAccessLevel::All);
});

test('a user with no roles defaults to own-only visibility', function () {
    $model = makeFixtureRecordModel();

    $user = User::factory()->create();
    $other = User::factory()->create();

    $mine = $model->newQuery()->create(['owner_id' => $user->id]);
    $model->newQuery()->create(['owner_id' => $other->id]);

    $visible = $model->newQuery()->visibleTo($user)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->id)->toBe($mine->id);
});

class ScopesByAccessLevelDummy
{
    use ScopesByAccessLevel;
}
