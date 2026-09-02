<?php

use App\Domain\Auth\Enums\LoginEvent;
use App\Domain\Auth\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the login screen can be rendered', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('Sign in')
        ->assertSee('Forgot password?');
});

test('a user can authenticate with valid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a user cannot authenticate with an incorrect password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user cannot authenticate with an unknown email address', function () {
    $this->post(route('login'), [
        'email' => 'nobody@example.com',
        'password' => 'whatever',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('login requires both an email and a password', function () {
    $this->post(route('login'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email', 'password']);

    $this->assertGuest();
});

test('an authenticated user is redirected away from the login screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

test('a user can sign out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('the session id is regenerated on login to prevent session fixation', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);

    $this->get(route('login'));
    $before = session()->getId();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ]);

    expect(session()->getId())->not->toBe($before);
});

test('repeated failed attempts lock the account out and are audited', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);

    // The login limiter allows five attempts per minute per email + IP.
    foreach (range(1, 5) as $attempt) {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');

    expect(LoginHistory::query()->where('event', LoginEvent::Lockout)->count())->toBe(1)
        ->and(LoginHistory::query()->where('event', LoginEvent::Failed)->count())->toBe(5);

    $this->assertGuest();
});
