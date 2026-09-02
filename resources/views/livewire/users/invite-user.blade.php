<div class="max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('settings.users') }}" wire:navigate class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
            <x-lucide-arrow-left class="h-4 w-4" aria-hidden="true" />
            Back to users
        </a>
        <h1 class="text-2xl font-semibold text-foreground">Invite user</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            They receive an email with a link to set their own password. The link expires in
            {{ config('auth.invitations.expire_days') }} days.
        </p>
    </div>

    <form wire:submit="invite" class="space-y-6 rounded-xl border border-border bg-card p-6">
        <div>
            <x-form.label for="email" required>Email address</x-form.label>
            <x-form.input id="email" type="email" wire:model="email" :invalid="$errors->has('email')" autocomplete="off" />
            <x-form.error for="email" />
        </div>

        <div>
            <x-form.label for="name">Name</x-form.label>
            <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" autocomplete="off" />
            <x-form.error for="name" />
            <p class="mt-1 text-xs text-muted-foreground">Optional — they can confirm their name when accepting.</p>
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
                name="teamId"
                label="Team"
                :options="$this->teamOptions()"
                :selected="$teamId"
                placeholder="No team"
                wire:model="teamId"
            />
        </div>
        <x-form.error for="roleId" />
        <x-form.error for="teamId" />

        <div class="flex justify-end gap-2 border-t border-border pt-6">
            <a href="{{ route('settings.users') }}" wire:navigate class="inline-flex items-center rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted">
                Cancel
            </a>
            <x-button type="submit" wire:loading.attr="disabled" wire:target="invite">
                <span wire:loading wire:target="invite" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Send invitation
            </x-button>
        </div>
    </form>
</div>
