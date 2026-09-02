<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * Enable and confirm two-factor auth directly, returning the plain-text secret
 * so tests can generate valid one-time codes.
 */
function enableTwoFactorFor(User $user): string
{
    app(EnableTwoFactorAuthentication::class)($user);

    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
}

function currentOtpFor(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

test('enabling two-factor authentication requires a confirmed password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('two-factor.enable'))
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a user can enable two-factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->recoveryCodes())->toHaveCount(8)
        // With the "confirm" option on, the secret is not active until confirmed.
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('a user can confirm two-factor authentication with a valid code', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    app(EnableTwoFactorAuthentication::class)($user);
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

    $this->post(route('two-factor.confirm'), ['code' => currentOtpFor($secret)])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('the two-factor secret and recovery codes are never serialised with the user', function () {
    $user = User::factory()->create();
    enableTwoFactorFor($user);

    $serialised = $user->fresh()->toArray();

    expect($serialised)->not->toHaveKey('two_factor_secret')
        ->and($serialised)->not->toHaveKey('two_factor_recovery_codes');
});

test('a user with two-factor enabled is sent to the challenge instead of being logged in', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);
    enableTwoFactorFor($user);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('the two-factor challenge screen can be rendered', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);
    enableTwoFactorFor($user);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery']);

    $this->get(route('two-factor.login'))
        ->assertSuccessful()
        ->assertSee('Two-factor authentication')
        ->assertSee('Use a recovery code instead');
});

test('a valid one-time code completes the login', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);
    $secret = enableTwoFactorFor($user);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery']);

    $this->post(route('two-factor.login'), ['code' => currentOtpFor($secret)])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('an invalid one-time code does not complete the login', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);
    enableTwoFactorFor($user);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery']);

    $this->post(route('two-factor.login'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('a recovery code completes the login and is then consumed', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);
    enableTwoFactorFor($user);

    $recoveryCode = $user->fresh()->recoveryCodes()[0];

    $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-horse-battery']);

    $this->post(route('two-factor.login'), ['recovery_code' => $recoveryCode])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    expect($user->fresh()->recoveryCodes())->not->toContain($recoveryCode);
});

test('a user can disable two-factor authentication', function () {
    $user = User::factory()->create();
    enableTwoFactorFor($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});
