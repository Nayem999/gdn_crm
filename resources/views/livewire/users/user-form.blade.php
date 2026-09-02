<div class="max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('settings.users') }}" wire:navigate class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
            <x-lucide-arrow-left class="h-4 w-4" aria-hidden="true" />
            Back to users
        </a>
        <h1 class="text-2xl font-semibold text-foreground">{{ $user ? 'Edit user' : 'Add user' }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ $user ? 'Update this person’s details and access.' : 'Create an account directly, or invite the person by email instead.' }}
        </p>
    </div>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-border bg-card p-6">
        <div class="flex items-center gap-4">
            @if ($avatar && $avatar->isPreviewable())
                <img src="{{ $avatar->temporaryUrl() }}" alt="Avatar preview" class="h-14 w-14 rounded-full object-cover">
            @else
                <x-avatar :user="$user" :initials="$user ? null : '?'" size="lg" />
            @endif

            <div>
                <x-form.label for="avatar">Avatar</x-form.label>
                <input id="avatar" type="file" wire:model="avatar" accept="image/*" class="text-sm text-muted-foreground">
                <div wire:loading wire:target="avatar" class="mt-1 text-xs text-muted-foreground">Uploading...</div>
                <x-form.error for="avatar" />
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

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-select
                name="roleId"
                label="Role"
                :options="$this->roleOptions()"
                :selected="$roleId"
                placeholder="No role"
                wire:model="roleId"
            />

            <x-select
                name="currentTeamId"
                label="Team"
                :options="$this->teamOptions()"
                :selected="$currentTeamId"
                placeholder="No team"
                wire:model="currentTeamId"
            />
        </div>
        <x-form.error for="roleId" />
        <x-form.error for="currentTeamId" />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-form.label for="password">{{ $user ? 'New password' : 'Password' }}</x-form.label>
                <x-form.password id="password" wire:model="password" :invalid="$errors->has('password')" autocomplete="new-password" />
                <x-form.error for="password" />
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ $user ? 'Leave blank to keep the current password.' : 'Leave blank to let them set it via a password reset.' }}
                </p>
            </div>

            <div>
                <x-form.label for="password_confirmation">Confirm password</x-form.label>
                <x-form.password id="password_confirmation" wire:model="password_confirmation" autocomplete="new-password" />
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-border pt-6">
            <a href="{{ route('settings.users') }}" wire:navigate class="inline-flex items-center rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted">
                Cancel
            </a>
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                {{ $user ? 'Save changes' : 'Create user' }}
            </x-button>
        </div>
    </form>
</div>
