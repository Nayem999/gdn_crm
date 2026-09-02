<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-foreground">My profile</h1>
        <p class="mt-1 text-sm text-muted-foreground">Your details, password and sign-in security.</p>
    </div>

    <div
        x-data="{ message: '' }"
        x-on:profile-updated.window="message = 'Profile updated.'; setTimeout(() => message = '', 3000)"
        x-on:password-updated.window="message = 'Password updated.'; setTimeout(() => message = '', 3000)"
        x-show="message"
        x-cloak
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    {{-- Details --}}
    <form wire:submit="updateDetails" class="space-y-6 rounded-xl border border-border bg-card p-6">
        <div>
            <h2 class="text-base font-semibold text-card-foreground">Details</h2>
            <p class="mt-1 text-sm text-muted-foreground">Changing your email address will require re-verification.</p>
        </div>

        <div class="flex items-center gap-4">
            {{-- isPreviewable() guards temporaryUrl(), which throws for a file
                 type the browser can't render (a PDF, say) before validation runs. --}}
            @if ($avatar && $avatar->isPreviewable())
                <img src="{{ $avatar->temporaryUrl() }}" alt="Avatar preview" class="h-20 w-20 rounded-full object-cover">
            @else
                <x-avatar :user="$this->user()" size="xl" />
            @endif

            <div>
                <x-form.label for="avatar">Avatar</x-form.label>
                <input id="avatar" type="file" wire:model="avatar" accept="image/*" class="text-sm text-muted-foreground">
                <div wire:loading wire:target="avatar" class="mt-1 text-xs text-muted-foreground">Uploading...</div>
                <x-form.error for="avatar" />

                @if ($this->user()->avatarUrl())
                    <button type="button" wire:click="removeAvatar" class="mt-2 text-xs font-medium text-destructive hover:underline">
                        Remove avatar
                    </button>
                @endif
            </div>
        </div>

        <div>
            <x-form.label for="name" required>Full name</x-form.label>
            <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" autocomplete="name" />
            <x-form.error for="name" />
        </div>

        <div>
            <x-form.label for="email" required>Email address</x-form.label>
            <x-form.input id="email" type="email" wire:model="email" :invalid="$errors->has('email')" autocomplete="username" />
            <x-form.error for="email" />
        </div>

        <div class="flex justify-end border-t border-border pt-6">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="updateDetails">
                <span wire:loading wire:target="updateDetails" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Save details
            </x-button>
        </div>
    </form>

    {{-- Password --}}
    <form wire:submit="updatePassword" class="space-y-6 rounded-xl border border-border bg-card p-6">
        <div>
            <h2 class="text-base font-semibold text-card-foreground">Password</h2>
            <p class="mt-1 text-sm text-muted-foreground">Changing your password signs out your other sessions.</p>
        </div>

        <div>
            <x-form.label for="current_password" required>Current password</x-form.label>
            <x-form.password id="current_password" wire:model="current_password" :invalid="$errors->has('current_password')" autocomplete="current-password" />
            <x-form.error for="current_password" />
        </div>

        <div>
            <x-form.label for="new_password" required>New password</x-form.label>
            <x-form.password id="new_password" wire:model="password" :invalid="$errors->has('password')" autocomplete="new-password" />
            <x-form.error for="password" />
        </div>

        <div>
            <x-form.label for="new_password_confirmation" required>Confirm new password</x-form.label>
            <x-form.password id="new_password_confirmation" wire:model="password_confirmation" autocomplete="new-password" />
        </div>

        <div class="flex justify-end border-t border-border pt-6">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="updatePassword">
                <span wire:loading wire:target="updatePassword" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Update password
            </x-button>
        </div>
    </form>

    {{-- Two-factor authentication --}}
    <div class="space-y-6 rounded-xl border border-border bg-card p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-card-foreground">Two-factor authentication</h2>
                <p class="mt-1 text-sm text-muted-foreground">Require a code from your authenticator app when signing in.</p>
            </div>

            @if ($this->user()->hasEnabledTwoFactorAuthentication())
                <x-status-chip color="emerald">Enabled</x-status-chip>
            @else
                <x-status-chip color="slate">Disabled</x-status-chip>
            @endif
        </div>

        @if (! $this->user()->two_factor_secret)
            <x-button type="button" variant="accent" wire:click="enableTwoFactor" wire:loading.attr="disabled" wire:target="enableTwoFactor">
                Enable two-factor authentication
            </x-button>
        @else
            @if ($showingTwoFactorSetup || ! $this->user()->hasEnabledTwoFactorAuthentication())
                <div class="space-y-4 rounded-lg border border-border bg-background p-4">
                    <p class="text-sm text-foreground">Scan this code with your authenticator app, then enter the six-digit code it shows.</p>

                    <div class="inline-flex rounded-lg bg-white p-3">
                        {!! $this->twoFactorQrCode() !!}
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Can't scan it? Enter this key manually:
                        <code class="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground">{{ $this->twoFactorSetupKey() }}</code>
                    </p>

                    <form wire:submit="confirmTwoFactor" class="flex flex-wrap items-end gap-2">
                        <div>
                            <x-form.label for="twoFactorCode" required>Authentication code</x-form.label>
                            <x-form.input
                                id="twoFactorCode"
                                wire:model="twoFactorCode"
                                :invalid="$errors->has('twoFactorCode')"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                class="max-w-40"
                            />
                        </div>
                        <x-button type="submit" wire:loading.attr="disabled" wire:target="confirmTwoFactor">Confirm</x-button>
                    </form>
                    <x-form.error for="twoFactorCode" />
                </div>
            @endif

            @if ($showingRecoveryCodes)
                <div class="space-y-3 rounded-lg border border-border bg-background p-4">
                    <p class="text-sm font-medium text-foreground">Recovery codes</p>
                    <p class="text-xs text-muted-foreground">Store these somewhere safe. Each one can be used once if you lose your device.</p>
                    <ul class="grid grid-cols-2 gap-1 font-mono text-xs text-foreground">
                        @foreach ($this->recoveryCodes() as $code)
                            <li class="rounded bg-muted px-2 py-1">{{ $code }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex flex-wrap gap-2 border-t border-border pt-6">
                @if ($this->user()->hasEnabledTwoFactorAuthentication() && ! $showingRecoveryCodes)
                    <x-button type="button" variant="secondary" wire:click="$set('showingRecoveryCodes', true)">
                        Show recovery codes
                    </x-button>
                @endif

                @if ($this->user()->hasEnabledTwoFactorAuthentication())
                    <x-button type="button" variant="secondary" wire:click="regenerateRecoveryCodes">
                        Regenerate recovery codes
                    </x-button>
                @endif

                <x-button type="button" variant="destructive" wire:click="disableTwoFactor" wire:loading.attr="disabled" wire:target="disableTwoFactor">
                    Disable
                </x-button>
            </div>
        @endif
    </div>
</div>
