<?php

use App\Livewire\Users\UserForm;
use App\Livewire\Users\UsersIndex;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function userWith(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission));
    }

    return $user;
}

function userAdmin(): User
{
    return userWith(['users.view', 'users.create', 'users.update', 'users.delete', 'users.invite']);
}

test('the users list is only reachable with the users.view permission', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(UsersIndex::class)->assertForbidden();
});

test('the users list shows the people with access', function () {
    $this->actingAs(userAdmin());

    User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    User::factory()->create(['name' => 'Grace Hopper']);

    Livewire::test(UsersIndex::class)
        ->assertSee('Ada Lovelace')
        ->assertSee('ada@example.com')
        ->assertSee('Grace Hopper');
});

test('the users list can be searched by name or email', function () {
    $this->actingAs(userAdmin());

    User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

    Livewire::test(UsersIndex::class)
        ->set('search', 'Lovelace')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper')
        ->set('search', 'grace@example')
        ->assertSee('Grace Hopper')
        ->assertDontSee('Ada Lovelace');
});

test('the users list paginates', function () {
    $this->actingAs(userAdmin());

    User::factory()->count(30)->create();

    Livewire::test(UsersIndex::class)
        ->set('perPage', 25)
        ->assertViewHas('users', fn ($users) => $users->count() === 25 && $users->total() === 31);
});

test('the users list shows an empty state when a search matches nothing', function () {
    $this->actingAs(userAdmin());

    Livewire::test(UsersIndex::class)
        ->set('search', 'nobody-by-that-name')
        ->assertSee('No users match')
        ->call('clearSearch')
        ->assertSet('search', '');
});

test('a user can be created with a role and a team', function () {
    $this->actingAs(userAdmin());

    $role = Role::create(['name' => 'Sales Manager']);
    $team = Team::factory()->create();

    Livewire::test(UserForm::class)
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery')
        ->set('roleId', (string) $role->id)
        ->set('currentTeamId', (string) $team->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.users'));

    $created = User::query()->where('email', 'ada@example.com')->sole();

    expect($created->name)->toBe('Ada Lovelace')
        ->and($created->current_team_id)->toBe($team->id)
        ->and($created->hasRole('Sales Manager'))->toBeTrue()
        ->and(Hash::check('correct-horse-battery', $created->password))->toBeTrue();
});

test('a user created without a password still gets an unusable random one', function () {
    $this->actingAs(userAdmin());

    Livewire::test(UserForm::class)
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->call('save')
        ->assertHasNoErrors();

    $created = User::query()->where('email', 'ada@example.com')->sole();

    expect($created->password)->not->toBeEmpty()
        ->and(Hash::check('', $created->password))->toBeFalse();
});

test('creating a user requires a name and a unique email', function () {
    $this->actingAs(userAdmin());

    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(UserForm::class)
        ->set('name', '')
        ->set('email', 'taken@example.com')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'email' => 'unique']);
});

test('creating a user rejects a mismatched password confirmation', function () {
    $this->actingAs(userAdmin());

    Livewire::test(UserForm::class)
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.com')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'something-else')
        ->call('save')
        ->assertHasErrors(['password' => 'confirmed']);
});

test('a user without the users.create permission cannot open the create form', function () {
    $this->actingAs(userWith(['users.view']));

    Livewire::test(UserForm::class)->assertForbidden();
});

test('an existing user can be edited', function () {
    $this->actingAs(userAdmin());

    $target = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    Livewire::test(UserForm::class, ['user' => $target])
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->set('email', 'new@example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->name)->toBe('New Name')
        ->and($target->fresh()->email)->toBe('new@example.com');
});

test('editing a user without entering a password keeps the existing one', function () {
    $this->actingAs(userAdmin());

    $target = User::factory()->create(['password' => Hash::make('original-password')]);

    Livewire::test(UserForm::class, ['user' => $target])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('original-password', $target->fresh()->password))->toBeTrue();
});

test('editing a user can replace their password', function () {
    $this->actingAs(userAdmin());

    $target = User::factory()->create(['password' => Hash::make('original-password')]);

    Livewire::test(UserForm::class, ['user' => $target])
        ->set('password', 'a-brand-new-password')
        ->set('password_confirmation', 'a-brand-new-password')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('a-brand-new-password', $target->fresh()->password))->toBeTrue();
});

test('a user avatar can be uploaded through the form', function () {
    Storage::fake('public');

    $this->actingAs(userAdmin());

    $target = User::factory()->create();

    Livewire::test(UserForm::class, ['user' => $target])
        ->set('avatar', UploadedFile::fake()->image('avatar.png'))
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->avatarUrl())->not->toBeNull();
});

test('a user can be soft deleted from the list', function () {
    $this->actingAs(userAdmin());

    $target = User::factory()->create(['name' => 'Departing Person']);

    Livewire::test(UsersIndex::class)
        ->call('delete', $target->id)
        ->assertHasNoErrors()
        ->assertDispatched('user-deleted');

    expect(User::query()->find($target->id))->toBeNull()
        ->and(User::withTrashed()->find($target->id)->trashed())->toBeTrue();
});

test('a user cannot delete their own account', function () {
    $admin = userAdmin();
    $this->actingAs($admin);

    Livewire::test(UsersIndex::class)
        ->call('delete', $admin->id)
        ->assertForbidden();

    expect(User::query()->find($admin->id))->not->toBeNull();
});

test('a soft deleted user can no longer authenticate', function () {
    $target = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);
    $target->delete();

    $this->post(route('login'), [
        'email' => $target->email,
        'password' => 'correct-horse-battery',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the users-per-page picker is a searchable select, keyed on its size', function () {
    $this->actingAs(userAdmin());

    $component = Livewire::test(UsersIndex::class);

    // <x-select> renders one native <select> for Tom Select to take over, so
    // equal counts mean nothing on the page is a plain dropdown.
    expect($component->html())->toContain('wire:key="users-per-page-25"')
        ->and(substr_count($component->html(), '<select'))
        ->toBe(substr_count($component->html(), 'tomSelectField('));

    $component->set('perPage', 100);

    expect($component->html())->toContain('wire:key="users-per-page-100"');
});
