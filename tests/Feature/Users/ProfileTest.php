<?php

use App\Livewire\Profile\ProfileForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

test('guests cannot reach the profile screen', function () {
    $this->get(route('profile'))->assertRedirect(route('login'));
});

test('the profile screen loads the signed-in user details', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $this->actingAs($user);

    $this->get(route('profile'))->assertSuccessful()->assertSee('My profile');

    Livewire::test(ProfileForm::class)
        ->assertSet('name', 'Ada Lovelace')
        ->assertSet('email', 'ada@example.com');
});

test('a user can update their own name and email', function () {
    $user = User::factory()->create(['email' => 'old@example.com', 'email_verified_at' => now()]);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('name', 'Ada Lovelace')
        ->set('email', 'new@example.com')
        ->call('updateDetails')
        ->assertHasNoErrors()
        ->assertDispatched('profile-updated');

    $user->refresh();

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('new@example.com')
        // Changing the address invalidates the previous verification.
        ->and($user->email_verified_at)->toBeNull();
});

test('keeping the same email leaves verification intact', function () {
    $user = User::factory()->create(['email' => 'same@example.com', 'email_verified_at' => now()]);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('name', 'Renamed Only')
        ->call('updateDetails')
        ->assertHasNoErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('the profile email must be unique across other users', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('email', 'taken@example.com')
        ->call('updateDetails')
        ->assertHasErrors(['email' => 'unique']);
});

test('a user can upload and then remove their avatar', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('avatar', UploadedFile::fake()->image('me.png'))
        ->call('updateDetails')
        ->assertHasNoErrors();

    expect($user->fresh()->avatarUrl())->not->toBeNull();

    Livewire::test(ProfileForm::class)
        ->call('removeAvatar');

    expect($user->fresh()->avatarUrl())->toBeNull();
});

test('an avatar must be an image within the size limit', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->create());

    Livewire::test(ProfileForm::class)
        ->set('avatar', UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'))
        ->call('updateDetails')
        ->assertHasErrors(['avatar' => 'image']);
});

test('a user can change their password with the current one', function () {
    $user = User::factory()->create(['password' => Hash::make('original-password')]);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('current_password', 'original-password')
        ->set('password', 'a-brand-new-password')
        ->set('password_confirmation', 'a-brand-new-password')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertDispatched('password-updated')
        ->assertSet('password', '');

    expect(Hash::check('a-brand-new-password', $user->fresh()->password))->toBeTrue();
});

test('changing a password requires the correct current password', function () {
    $user = User::factory()->create(['password' => Hash::make('original-password')]);

    $this->actingAs($user);

    Livewire::test(ProfileForm::class)
        ->set('current_password', 'not-the-current-password')
        ->set('password', 'a-brand-new-password')
        ->set('password_confirmation', 'a-brand-new-password')
        ->call('updatePassword')
        ->assertHasErrors('current_password');

    expect(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
});

test('a new password must be confirmed and long enough', function () {
    $this->actingAs(User::factory()->create(['password' => Hash::make('original-password')]));

    Livewire::test(ProfileForm::class)
        ->set('current_password', 'original-password')
        ->set('password', 'short')
        ->set('password_confirmation', 'mismatch')
        ->call('updatePassword')
        ->assertHasErrors(['password']);
});

test('a user can enable two-factor authentication from their profile', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(ProfileForm::class)
        ->call('enableTwoFactor')
        ->assertSet('showingTwoFactorSetup', true);

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        // Not active until a code confirms the authenticator is paired.
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $component->assertSee('svg', escape: false);
});

test('a valid code confirms two-factor and reveals the recovery codes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(EnableTwoFactorAuthentication::class)($user);
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

    Livewire::test(ProfileForm::class)
        ->set('twoFactorCode', app(Google2FA::class)->getCurrentOtp($secret))
        ->call('confirmTwoFactor')
        ->assertHasNoErrors()
        ->assertSet('showingRecoveryCodes', true);

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('an invalid code does not confirm two-factor', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(EnableTwoFactorAuthentication::class)($user);

    Livewire::test(ProfileForm::class)
        ->set('twoFactorCode', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors('twoFactorCode');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('recovery codes can be regenerated and two-factor can be switched off', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(EnableTwoFactorAuthentication::class)($user);
    $original = $user->fresh()->recoveryCodes();

    $component = Livewire::test(ProfileForm::class)->call('regenerateRecoveryCodes');

    expect($user->fresh()->recoveryCodes())->not->toBe($original);

    $component->call('disableTwoFactor');

    expect($user->fresh()->two_factor_secret)->toBeNull();
});
