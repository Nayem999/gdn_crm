<div>
    @error('token')
        <x-alert variant="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <form wire:submit="accept" class="space-y-5">
        <div>
            <x-form.label for="email">Email address</x-form.label>
            <x-form.input id="email" type="email" value="{{ $email }}" disabled readonly />
            <p class="mt-1 text-xs text-muted-foreground">This is the address the invitation was sent to.</p>
        </div>

        <div>
            <x-form.label for="name" required>Full name</x-form.label>
            <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" autocomplete="name" autofocus />
            <x-form.error for="name" />
        </div>

        <div>
            <x-form.label for="password" required>Password</x-form.label>
            <x-form.password id="password" wire:model="password" :invalid="$errors->has('password')" autocomplete="new-password" />
            <x-form.error for="password" />
            <p class="mt-1 text-xs text-muted-foreground">At least 8 characters.</p>
        </div>

        <div>
            <x-form.label for="password_confirmation" required>Confirm password</x-form.label>
            <x-form.password id="password_confirmation" wire:model="password_confirmation" autocomplete="new-password" />
        </div>

        <x-button type="submit" full wire:loading.attr="disabled" wire:target="accept">
            <span wire:loading wire:target="accept" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
            Create my account
        </x-button>
    </form>
</div>
