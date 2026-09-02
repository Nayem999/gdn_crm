<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Livewire\Roles\RoleForm;
use App\Livewire\Roles\RolesIndex;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function roleAdmin(array $permissions = ['roles.view', 'roles.create', 'roles.update', 'roles.delete']): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission));
    }

    return $user;
}

test('the roles screen needs the roles.view permission', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(RolesIndex::class)->assertForbidden();
});

test('the roles list shows each role with its access level and counts', function () {
    $this->actingAs(roleAdmin());

    $role = Role::create(['name' => 'Sales Rep', 'data_access_level' => DataAccessLevel::Team->value]);
    $role->givePermissionTo(Permission::findOrCreate('users.view'));
    User::factory()->create()->assignRole($role);

    Livewire::test(RolesIndex::class)
        ->assertSee('Sales Rep')
        ->assertSee('Team records');
});

test('a role can be created with permissions and an access level', function () {
    $this->actingAs(roleAdmin());

    Livewire::test(RoleForm::class)
        ->set('name', 'Sales Rep')
        ->set('dataAccessLevel', DataAccessLevel::Team->value)
        ->set('permissions', ['users.view', 'teams.view'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.roles'));

    $role = Role::query()->where('name', 'Sales Rep')->sole();

    expect($role->data_access_level)->toBe(DataAccessLevel::Team->value)
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['teams.view', 'users.view']);
});

test('a role defaults to the most restrictive access level', function () {
    $this->actingAs(roleAdmin());

    Livewire::test(RoleForm::class)
        ->assertSet('dataAccessLevel', DataAccessLevel::Own->value)
        ->set('name', 'Junior')
        ->call('save')
        ->assertHasNoErrors();

    expect(Role::query()->where('name', 'Junior')->sole()->data_access_level)
        ->toBe(DataAccessLevel::Own->value);
});

test('creating a role requires a unique name and a valid access level', function () {
    $this->actingAs(roleAdmin());

    Role::create(['name' => 'Sales Rep']);

    Livewire::test(RoleForm::class)
        ->set('name', 'Sales Rep')
        ->set('dataAccessLevel', 'everything')
        ->call('save')
        ->assertHasErrors(['name' => 'unique', 'dataAccessLevel']);
});

test('a permission outside the catalogue is rejected', function () {
    $this->actingAs(roleAdmin());

    Livewire::test(RoleForm::class)
        ->set('name', 'Sneaky')
        ->set('permissions', ['billing.refund-everything'])
        ->call('save')
        ->assertHasErrors('permissions.0');

    expect(Role::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

test('the matrix can select and clear a whole permission group', function () {
    $this->actingAs(roleAdmin());

    $usersGroup = array_keys(PermissionCatalogue::groups()['users']['permissions']);

    $component = Livewire::test(RoleForm::class)
        ->call('toggleGroup', 'users');

    expect($component->get('permissions'))->toEqualCanonicalizing($usersGroup);

    $component->call('toggleGroup', 'users');

    expect($component->get('permissions'))->toBe([]);
});

test('toggling one group leaves the others untouched', function () {
    $this->actingAs(roleAdmin());

    $component = Livewire::test(RoleForm::class)
        ->set('permissions', ['company.view'])
        ->call('toggleGroup', 'teams');

    expect($component->get('permissions'))->toContain('company.view')
        ->and($component->instance()->groupIsFullySelected('teams'))->toBeTrue()
        ->and($component->instance()->groupIsFullySelected('users'))->toBeFalse();
});

test('the group counter reflects the current selection', function () {
    $this->actingAs(roleAdmin());

    $component = Livewire::test(RoleForm::class)->set('permissions', ['users.view', 'users.create']);

    expect($component->instance()->selectedCountFor('users'))->toBe(2)
        ->and($component->instance()->selectedCountFor('teams'))->toBe(0);
});

test('an existing role loads its permissions into the matrix', function () {
    $this->actingAs(roleAdmin());

    $role = Role::create(['name' => 'Sales Rep', 'data_access_level' => DataAccessLevel::Team->value]);
    $role->syncPermissions([Permission::findOrCreate('users.view'), Permission::findOrCreate('teams.view')]);

    Livewire::test(RoleForm::class, ['role' => $role])
        ->assertSet('name', 'Sales Rep')
        ->assertSet('dataAccessLevel', DataAccessLevel::Team->value)
        ->assertSet('permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['teams.view', 'users.view']);
});

test('a role can have its permissions and access level changed', function () {
    $this->actingAs(roleAdmin());

    $role = Role::create(['name' => 'Sales Rep', 'data_access_level' => DataAccessLevel::Own->value]);
    $role->givePermissionTo(Permission::findOrCreate('users.view'));

    Livewire::test(RoleForm::class, ['role' => $role])
        ->set('dataAccessLevel', DataAccessLevel::All->value)
        ->set('permissions', ['teams.view', 'teams.update'])
        ->call('save')
        ->assertHasNoErrors();

    $role->refresh();

    expect($role->data_access_level)->toBe(DataAccessLevel::All->value)
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['teams.update', 'teams.view'])
        // The permission it used to hold is gone, not merely added to.
        ->and($role->hasPermissionTo('users.view'))->toBeFalse();
});

test('a role can be deleted when nobody holds it', function () {
    $this->actingAs(roleAdmin());

    $role = Role::create(['name' => 'Unused']);

    Livewire::test(RolesIndex::class)
        ->call('delete', $role->id)
        ->assertHasNoErrors()
        ->assertDispatched('role-deleted');

    expect(Role::query()->find($role->id))->toBeNull();
});

test('a role still assigned to someone cannot be deleted', function () {
    $this->actingAs(roleAdmin());

    $role = Role::create(['name' => 'In Use']);
    User::factory()->create()->assignRole($role);

    Livewire::test(RolesIndex::class)
        ->call('delete', $role->id)
        ->assertHasErrors('delete');

    expect(Role::query()->find($role->id))->not->toBeNull();
});

test('editing and deleting need their own permissions', function () {
    $this->actingAs(roleAdmin(['roles.view']));

    $role = Role::create(['name' => 'Sales Rep']);

    Livewire::test(RoleForm::class, ['role' => $role])->assertForbidden();

    Livewire::test(RolesIndex::class)
        ->call('delete', $role->id)
        ->assertForbidden();
});

test('the super admin role is protected from editing and deletion', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actingAs(roleAdmin());

    $superAdmin = Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN_ROLE)->sole();

    Livewire::test(RoleForm::class, ['role' => $superAdmin])->assertForbidden();

    Livewire::test(RolesIndex::class)
        ->call('delete', $superAdmin->id)
        ->assertForbidden();

    expect(Role::query()->find($superAdmin->id))->not->toBeNull();
});

test('the seeder gives the super admin role every catalogued permission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $superAdmin = Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN_ROLE)->sole();

    expect($superAdmin->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionCatalogue::all())->sort()->values()->all())
        ->and($superAdmin->data_access_level)->toBe(DataAccessLevel::All->value);
});

test('the seeder is safe to run twice', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN_ROLE)->count())->toBe(1)
        ->and(Permission::query()->count())->toBe(count(PermissionCatalogue::all()));
});

test('a user holding the super admin role can reach every managed screen', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(PermissionCatalogue::SUPER_ADMIN_ROLE);

    $this->actingAs($user);

    foreach ([route('settings.roles'), route('settings.users'), route('settings.teams'), route('settings.company')] as $url) {
        $this->get($url)->assertSuccessful();
    }
});
