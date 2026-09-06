<?php

use App\Domain\Access\PermissionCatalogue;
use App\Models\User;

test('the registration screen can be rendered', function () {
    $this->get(route('register'))
        ->assertSuccessful()
        ->assertSee('Create your account');
});

test('a new user can register', function () {
    $this->post(route('register'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    expect(User::query()->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('registration requires a name, email and password', function () {
    $this->post(route('register'), [])
        ->assertSessionHasErrors(['name', 'email', 'password']);

    $this->assertGuest();
});

test('registration rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->post(route('register'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'jane@example.com')->count())->toBe(1);
});

test('registration requires the password to be confirmed', function () {
    $this->post(route('register'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'something-else',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('registration rejects a password shorter than eight characters', function () {
    $this->post(route('register'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('the first account registered owns the installation and holds every permission', function () {
    expect(User::query()->count())->toBe(0);

    $this->post(route('register'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('dashboard'));

    $owner = User::query()->where('email', 'jane@example.com')->sole();

    expect($owner->hasRole(PermissionCatalogue::SUPER_ADMIN_ROLE))->toBeTrue();

    foreach (PermissionCatalogue::all() as $permission) {
        expect($owner->can($permission))->toBeTrue("the owner should hold {$permission}");
    }
});

test('a later registration gets no role, so access stays something an owner grants', function () {
    User::factory()->create();

    $this->post(route('register'), [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $user = User::query()->where('email', 'john@example.com')->sole();

    expect($user->roles)->toBeEmpty()
        ->and($user->can('leads.view'))->toBeFalse();
});
