<x-layouts.guest title="Create account" heading="Create your account" subheading="Set up your access to the CRM.">
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <div>
            <x-form.label for="name" required>Full name</x-form.label>
            <x-form.input
                id="name"
                name="name"
                :value="old('name')"
                :invalid="$errors->has('name')"
                autocomplete="name"
                required
                autofocus
            />
            <x-form.error for="name" />
        </div>

        <div>
            <x-form.label for="email" required>Email address</x-form.label>
            <x-form.input
                id="email"
                name="email"
                type="email"
                :value="old('email')"
                :invalid="$errors->has('email')"
                autocomplete="username"
                required
            />
            <x-form.error for="email" />
        </div>

        <div>
            <x-form.label for="password" required>Password</x-form.label>
            <x-form.input
                id="password"
                name="password"
                type="password"
                :invalid="$errors->has('password')"
                autocomplete="new-password"
                required
            />
            <x-form.error for="password" />
            <p class="mt-1 text-xs text-muted-foreground">At least 8 characters.</p>
        </div>

        <div>
            <x-form.label for="password_confirmation" required>Confirm password</x-form.label>
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

        <x-button type="submit" full>Create account</x-button>
    </form>

    <x-slot:footer>
        Already have an account?
        <a href="{{ route('login') }}" class="font-medium text-accent hover:underline">Sign in</a>
    </x-slot:footer>
</x-layouts.guest>
