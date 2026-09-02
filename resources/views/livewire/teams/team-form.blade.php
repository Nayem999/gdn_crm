<div class="max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('settings.teams') }}" wire:navigate class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
            <x-lucide-arrow-left class="h-4 w-4" aria-hidden="true" />
            Back to teams
        </a>
        <h1 class="text-2xl font-semibold text-foreground">{{ $team ? 'Edit team' : 'Add team' }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ $team
                ? 'Update this department, where it sits, and who belongs to it.'
                : 'Group people into a department. Nest it under another team to build a hierarchy.' }}
        </p>
    </div>

    <form wire:submit="save" class="space-y-6 rounded-xl border border-border bg-card p-6">
        <div>
            <x-form.label for="name" required>Team name</x-form.label>
            <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" />
            <x-form.error for="name" />
        </div>

        <div>
            <x-form.label for="description">Description</x-form.label>
            <textarea
                id="description"
                wire:model="description"
                rows="2"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
            ></textarea>
            <x-form.error for="description" />
        </div>

        <div>
            <x-select
                name="parentId"
                label="Parent team"
                :options="$this->parentOptions()"
                :selected="$parentId"
                placeholder="No parent (top level)"
                wire:model="parentId"
            />
            <x-form.error for="parentId" />
            @if ($team)
                <p class="mt-1 text-xs text-muted-foreground">This team and its own sub-teams are not offered, since that would loop the hierarchy.</p>
            @endif
        </div>

        <div>
            <x-select
                name="memberIds"
                label="Members"
                :options="$this->memberOptions()"
                :selected="$memberIds"
                placeholder="Search people..."
                multiple
                wire:model="memberIds"
            />
            <x-form.error for="memberIds" />
            <p class="mt-1 text-xs text-muted-foreground">
                Anyone added who has no active team yet will have this one set as theirs, which is what team-level record visibility follows.
            </p>
        </div>

        <div class="flex justify-end gap-2 border-t border-border pt-6">
            <a href="{{ route('settings.teams') }}" wire:navigate class="inline-flex items-center rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted">
                Cancel
            </a>
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                {{ $team ? 'Save changes' : 'Create team' }}
            </x-button>
        </div>
    </form>
</div>
