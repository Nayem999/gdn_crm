<?php

use App\Domain\Access\Actions\UpdateRoleAction;
use App\Domain\Access\DTOs\RoleData;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Livewire\Roles\RoleForm;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Stand-in owned record, so access levels can be asserted before the real
 * business models arrive in Phase 2.
 */
function accessScopedModel(): Model
{
    if (! Schema::hasTable('access_level_records')) {
        Schema::create('access_level_records', function ($table) {
            $table->id();
            $table->foreignId('owner_id');
            $table->timestamps();
        });
    }

    return new class extends Model
    {
        use ScopesByAccessLevel;

        protected $table = 'access_level_records';

        protected $fillable = ['owner_id'];
    };
}

/**
 * Three owners: the acting user, a teammate sharing their active team, and an
 * outsider on another team — plus one record each.
 *
 * @return array{model: Model, user: User, role: Role, records: array<string, int>}
 */
function accessLevelFixture(DataAccessLevel $level): array
{
    $model = accessScopedModel();

    $team = Team::factory()->create();
    $role = Role::create(['name' => 'scoped-'.uniqid(), 'data_access_level' => $level->value]);

    $user = User::factory()->create(['current_team_id' => $team->id]);
    $user->assignRole($role);

    $teammate = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create(['current_team_id' => Team::factory()->create()->id]);

    return [
        'model' => $model,
        'user' => $user,
        'role' => $role,
        'records' => [
            'mine' => $model->newQuery()->create(['owner_id' => $user->id])->id,
            'teammate' => $model->newQuery()->create(['owner_id' => $teammate->id])->id,
            'outsider' => $model->newQuery()->create(['owner_id' => $outsider->id])->id,
        ],
    ];
}

test('own access returns only the acting user records', function () {
    ['model' => $model, 'user' => $user, 'records' => $records] = accessLevelFixture(DataAccessLevel::Own);

    expect($model->newQuery()->visibleTo($user)->pluck('id')->all())->toBe([$records['mine']]);
});

test('team access returns the acting user and their teammates records', function () {
    ['model' => $model, 'user' => $user, 'records' => $records] = accessLevelFixture(DataAccessLevel::Team);

    expect($model->newQuery()->visibleTo($user)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$records['mine'], $records['teammate']])->sort()->values()->all());
});

test('all access returns every record', function () {
    ['model' => $model, 'user' => $user, 'records' => $records] = accessLevelFixture(DataAccessLevel::All);

    expect($model->newQuery()->visibleTo($user)->pluck('id')->sort()->values()->all())
        ->toBe(collect($records)->sort()->values()->all());
});

test('a user with no role at all sees only their own records', function () {
    $model = accessScopedModel();

    $user = User::factory()->create();
    $other = User::factory()->create();

    $mine = $model->newQuery()->create(['owner_id' => $user->id]);
    $model->newQuery()->create(['owner_id' => $other->id]);

    expect($model->newQuery()->visibleTo($user)->pluck('id')->all())->toBe([$mine->id]);
});

test('changing a role access level in the UI immediately changes what its holders see', function () {
    ['model' => $model, 'user' => $user, 'role' => $role, 'records' => $records] = accessLevelFixture(DataAccessLevel::Own);

    expect($model->newQuery()->visibleTo($user)->count())->toBe(1);

    $admin = User::factory()->create();
    $admin->givePermissionTo(Permission::findOrCreate('roles.update'));
    $this->actingAs($admin);

    // Widen the role to team level through the matrix screen.
    Livewire::test(RoleForm::class, ['role' => $role])
        ->set('dataAccessLevel', DataAccessLevel::Team->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($model->newQuery()->visibleTo($user->fresh())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$records['mine'], $records['teammate']])->sort()->values()->all());

    // And widen it again to everything.
    Livewire::test(RoleForm::class, ['role' => $role->fresh()])
        ->set('dataAccessLevel', DataAccessLevel::All->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($model->newQuery()->visibleTo($user->fresh())->count())->toBe(3);
});

test('narrowing a role access level takes visibility away again', function () {
    ['model' => $model, 'user' => $user, 'role' => $role, 'records' => $records] = accessLevelFixture(DataAccessLevel::All);

    expect($model->newQuery()->visibleTo($user)->count())->toBe(3);

    app(UpdateRoleAction::class)($role, new RoleData(
        name: $role->name,
        dataAccessLevel: DataAccessLevel::Own,
    ));

    expect($model->newQuery()->visibleTo($user->fresh())->pluck('id')->all())->toBe([$records['mine']]);
});

test('the broadest level wins when a user holds several roles', function () {
    $model = accessScopedModel();

    $team = Team::factory()->create();
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $teammate = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create();

    $user->assignRole(Role::create(['name' => 'narrow', 'data_access_level' => DataAccessLevel::Own->value]));
    $user->assignRole(Role::create(['name' => 'wide', 'data_access_level' => DataAccessLevel::All->value]));

    $model->newQuery()->create(['owner_id' => $user->id]);
    $model->newQuery()->create(['owner_id' => $teammate->id]);
    $model->newQuery()->create(['owner_id' => $outsider->id]);

    expect($model->newQuery()->visibleTo($user->fresh())->count())->toBe(3);
});

test('team access with no active team falls back to own records only', function () {
    $model = accessScopedModel();

    $user = User::factory()->create(['current_team_id' => null]);
    $user->assignRole(Role::create(['name' => 'teamless', 'data_access_level' => DataAccessLevel::Team->value]));

    $otherTeamless = User::factory()->create(['current_team_id' => null]);

    $mine = $model->newQuery()->create(['owner_id' => $user->id]);
    $model->newQuery()->create(['owner_id' => $otherTeamless->id]);

    // Never a broad "everyone without a team" match.
    expect($model->newQuery()->visibleTo($user->fresh())->pluck('id')->all())->toBe([$mine->id]);
});

test('every access level exposes a label, description and chip colour', function (DataAccessLevel $level) {
    expect($level->label())->not->toBeEmpty()
        ->and($level->description())->not->toBeEmpty()
        ->and($level->color())->not->toBeEmpty()
        ->and(DataAccessLevel::options())->toHaveKey($level->value);
})->with(DataAccessLevel::cases());
