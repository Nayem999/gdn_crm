<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('the forgot password screen can be rendered', function () {
    $this->get(route('password.request'))
        ->assertSuccessful()
        ->assertSee('Forgot your password?');
});

test('a password reset link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('requesting a reset link requires a valid email', function () {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

test('the reset password screen can be rendered from a reset link', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) {
        $this->get(route('password.reset', ['token' => $notification->token]))
            ->assertSuccessful()
            ->assertSee('Choose a new password');

        return true;
    });
});

test('a password can be reset with a valid token', function () {
    Notification::fake();

    $user = User::factory()->create(['password' => Hash::make('old-password')]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();

        return true;
    });
});

test('a password is not reset with an invalid token', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password')]);

    $this->post(route('password.update'), [
        'token' => 'this-token-is-not-real',
        'email' => $user->email,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});
