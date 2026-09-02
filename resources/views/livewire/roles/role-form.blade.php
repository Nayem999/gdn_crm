@php use App\Domain\Shared\Enums\DataAccessLevel; @endphp

<div class="max-w-3xl">
    <div class="mb-6">
        <a href="{{ route('settings.roles') }}" wire:navigate class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
            <x-lucide-arrow-left class="h-4 w-4" aria-hidden="true" />
            Back to roles
        </a>
        <h1 class="text-2xl font-semibold text-foreground">{{ $role ? 'Edit role' : 'Add role' }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Pick what this role can do, and how much of the CRM's data it can see.
        </p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-6 rounded-xl border border-border bg-card p-6">
            <div>
                <x-form.label for="name" required>Role name</x-form.label>
                <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" />
                <x-form.error for="name" />
            </div>

            <div>
                <x-select
                    name="dataAccessLevel"
                    label="Data access level"
                    :options="DataAccessLevel::options()"
                    :selected="$dataAccessLevel"
                    wire:model.live="dataAccessLevel"
                    required
                />
                <x-form.error for="dataAccessLevel" />
                <p class="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                    <x-status-chip :color="$this->selectedLevel()->color()">{{ $this->selectedLevel()->label() }}</x-status-chip>
                    {{ $this->selectedLevel()->description() }}
                </p>
            </div>
        </div>

        {{-- Permission matrix --}}
        <div class="rounded-xl border border-border bg-card">
            <div class="border-b border-border p-6">
                <h2 class="text-base font-semibold text-card-foreground">Permissions</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Every permission here is checked by the application. Access level decides which records are visible; these decide what can be done with them.
                </p>
                <x-form.error for="permissions" class="mt-2" />
            </div>

            <div class="divide-y divide-border">
                @foreach ($this->groups() as $key => $group)
                    <div class="p-6" wire:key="group-{{ $key }}">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                    <x-dynamic-component :component="'lucide-'.$group['icon']" class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div>
                                    <p class="text-sm font-semibold text-foreground">{{ $group['label'] }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ $this->selectedCountFor($key) }} of {{ count($group['permissions']) }} selected
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                wire:click="toggleGroup('{{ $key }}')"
                                class="text-sm font-medium text-accent hover:underline"
                            >
                                {{ $this->groupIsFullySelected($key) ? 'Clear all' : 'Select all' }}
                            </button>
                        </div>

                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($group['permissions'] as $permission => $label)
                                <label
                                    for="perm-{{ $permission }}"
                                    class="flex cursor-pointer items-start gap-2 rounded-lg border border-border p-3 transition-colors hover:bg-muted"
                                    wire:key="perm-{{ $permission }}"
                                >
                                    <input
                                        id="perm-{{ $permission }}"
                                        type="checkbox"
                                        value="{{ $permission }}"
                                        wire:model.live="permissions"
                                        class="mt-0.5 h-4 w-4 rounded border-border text-accent focus:ring-2 focus:ring-accent/40"
                                    >
                                    <span class="min-w-0">
                                        <span class="block text-sm text-foreground">{{ $label }}</span>
                                        <code class="block truncate text-xs text-muted-foreground">{{ $permission }}</code>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end gap-2 border-t border-border p-6">
                <a href="{{ route('settings.roles') }}" wire:navigate class="inline-flex items-center rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted">
                    Cancel
                </a>
                <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                    {{ $role ? 'Save changes' : 'Create role' }}
                </x-button>
            </div>
        </div>
    </form>
</div>
