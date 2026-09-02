<x-layouts.guest
    title="Confirm password"
    heading="Confirm your password"
    subheading="This is a secure area. Please confirm your password before continuing."
>
    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-5">
        @csrf

        <div>
            <x-form.label for="password" required>Password</x-form.label>
            <x-form.password
                id="password"
                name="password"
                :invalid="$errors->has('password')"
                autocomplete="current-password"
                required
                autofocus
            />
            <x-form.error for="password" />
        </div>

        <x-button type="submit" full>Confirm</x-button>
    </form>
</x-layouts.guest>
