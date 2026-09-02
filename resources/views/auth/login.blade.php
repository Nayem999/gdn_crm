@php use Laravel\Fortify\Features; @endphp

<x-layouts.guest title="Sign in" heading="Sign in" subheading="Welcome back. Enter your details to continue.">
    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    @error('email')
        <x-alert variant="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
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
        </div>

        <div>
            <x-form.label for="password" required>Password</x-form.label>
            <x-form.input
                id="password"
                name="password"
                type="password"
                :invalid="$errors->has('password')"
                autocomplete="current-password"
                required
            />
            <x-form.error for="password" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember" class="flex items-center gap-2 text-sm text-muted-foreground">
                <input
                    id="remember"
                    name="remember"
                    type="checkbox"
                    class="h-4 w-4 rounded border-border text-accent focus:ring-2 focus:ring-accent/40"
                >
                Remember me
            </label>

            @if (Features::enabled(Features::resetPasswords()))
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-accent hover:underline">
                    Forgot password?
                </a>
            @endif
        </div>

        <x-button type="submit" full>Sign in</x-button>
    </form>

    @if (Features::enabled(Features::registration()))
        <x-slot:footer>
            Don't have an account?
            <a href="{{ route('register') }}" class="font-medium text-accent hover:underline">Create one</a>
        </x-slot:footer>
    @endif
</x-layouts.guest>
