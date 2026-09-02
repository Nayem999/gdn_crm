<?php

namespace App\Livewire\Profile;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('My profile')]
class ProfileForm extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    /** @var TemporaryUploadedFile|null */
    public $avatar = null;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** Whether the QR code and setup key are on screen. */
    public bool $showingTwoFactorSetup = false;

    public string $twoFactorCode = '';

    public bool $showingRecoveryCodes = false;

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function updateDetails(): void
    {
        $user = $this->user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id)->withoutTrashed(),
            ],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);

        $emailChanged = $validated['email'] !== $user->email;

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($this->avatar !== null) {
            $user->addMedia($this->avatar)->toMediaCollection('avatar');
            $this->refreshMedia($user);
            $this->avatar = null;
        }

        $this->dispatch('profile-updated');
    }

    public function removeAvatar(): void
    {
        $user = $this->user();

        $this->refreshMedia($user);
        $user->clearMediaCollection('avatar');

        $this->dispatch('profile-updated');
    }

    /**
     * Drop any cached `media` relation before reading or mutating it.
     *
     * The guard hands back the same User instance for the whole request, and the
     * topbar avatar has usually already loaded `media` by then. Mutating against
     * that stale collection silently does nothing — clearMediaCollection would
     * iterate an empty cache while rows still exist.
     */
    private function refreshMedia(User $user): void
    {
        $user->unsetRelation('media');
    }

    public function updatePassword(): void
    {
        $validated = $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $this->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password does not match your current password.',
            ]);
        }

        $this->user()->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $this->reset(['current_password', 'password', 'password_confirmation']);

        $this->dispatch('password-updated');
    }

    public function enableTwoFactor(EnableTwoFactorAuthentication $enable): void
    {
        $enable($this->user());

        $this->showingTwoFactorSetup = true;
    }

    public function confirmTwoFactor(): void
    {
        $this->validate([
            'twoFactorCode' => ['required', 'string'],
        ]);

        $user = $this->user();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        if (! app(TwoFactorAuthenticationProvider::class)->verify($secret, $this->twoFactorCode)) {
            throw ValidationException::withMessages([
                'twoFactorCode' => 'That code is not valid. Check your authenticator app and try again.',
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->reset(['twoFactorCode']);
        $this->showingTwoFactorSetup = false;
        $this->showingRecoveryCodes = true;
    }

    public function disableTwoFactor(DisableTwoFactorAuthentication $disable): void
    {
        $disable($this->user());

        $this->showingTwoFactorSetup = false;
        $this->showingRecoveryCodes = false;
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $generate($this->user());

        $this->showingRecoveryCodes = true;
    }

    public function twoFactorQrCode(): ?string
    {
        $user = $this->user();

        return $user->two_factor_secret ? $user->twoFactorQrCodeSvg() : null;
    }

    public function twoFactorSetupKey(): ?string
    {
        $user = $this->user();

        return $user->two_factor_secret
            ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret)
            : null;
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        $user = $this->user();

        return $user->two_factor_secret ? $user->recoveryCodes() : [];
    }

    public function render(): View
    {
        return view('livewire.profile.profile-form');
    }
}
