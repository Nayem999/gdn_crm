<x-layouts.guest title="Two-factor authentication" heading="Two-factor authentication">
    <div x-data="{ recovery: false }">
        <p class="text-sm text-muted-foreground" x-show="! recovery">
            Enter the six-digit code from your authenticator app to finish signing in.
        </p>
        <p class="text-sm text-muted-foreground" x-show="recovery" x-cloak>
            Enter one of your emergency recovery codes. Each code can only be used once.
        </p>

        @error('code')
            <x-alert variant="error" class="mt-4">{{ $message }}</x-alert>
        @enderror

        @error('recovery_code')
            <x-alert variant="error" class="mt-4">{{ $message }}</x-alert>
        @enderror

        <form method="POST" action="{{ route('two-factor.login') }}" class="mt-6 space-y-5">
            @csrf

            <div x-show="! recovery">
                <x-form.label for="code">Authentication code</x-form.label>
                <x-form.input
                    id="code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    :invalid="$errors->has('code')"
                    x-ref="code"
                />
            </div>

            <div x-show="recovery" x-cloak>
                <x-form.label for="recovery_code">Recovery code</x-form.label>
                <x-form.input
                    id="recovery_code"
                    name="recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                    :invalid="$errors->has('recovery_code')"
                    x-ref="recovery_code"
                />
            </div>

            <x-button type="submit" full>Verify</x-button>

            <button
                type="button"
                class="w-full text-center text-sm font-medium text-accent hover:underline"
                x-on:click="recovery = ! recovery; $nextTick(() => (recovery ? $refs.recovery_code : $refs.code).focus())"
            >
                <span x-show="! recovery">Use a recovery code instead</span>
                <span x-show="recovery" x-cloak>Use an authentication code instead</span>
            </button>
        </form>
    </div>
</x-layouts.guest>
