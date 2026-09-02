<x-layouts.guest title="Reset password" heading="Choose a new password" subheading="Enter a new password for your account.">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <x-form.label for="email" required>Email address</x-form.label>
            <x-form.input
                id="email"
                name="email"
                type="email"
                :value="old('email', $request->email)"
                :invalid="$errors->has('email')"
                autocomplete="username"
                required
                autofocus
            />
            <x-form.error for="email" />
        </div>

        <div>
            <x-form.label for="password" required>New password</x-form.label>
            <x-form.input
                id="password"
                name="password"
                type="password"
                :invalid="$errors->has('password')"
                autocomplete="new-password"
                required
            />
            <x-form.error for="password" />
        </div>

        <div>
            <x-form.label for="password_confirmation" required>Confirm new password</x-form.label>
            <x-form.input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                :invalid="$errors->has('password_confirmation')"
                autocomplete="new-password"
                required
            />
            <x-form.error for="password_confirmation" />
        </div>

        <x-button type="submit" full>Reset password</x-button>
    </form>
</x-layouts.guest>
