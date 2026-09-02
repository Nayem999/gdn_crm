<x-layouts.guest
    title="Forgot password"
    heading="Forgot your password?"
    subheading="Enter your email address and we'll send you a reset link."
>
    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

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
                autofocus
            />
            <x-form.error for="email" />
        </div>

        <x-button type="submit" full>Email password reset link</x-button>
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-medium text-accent hover:underline">Back to sign in</a>
    </x-slot:footer>
</x-layouts.guest>
