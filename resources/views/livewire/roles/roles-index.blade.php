@php use App\Domain\Shared\Enums\DataAccessLevel; @endphp

<div>
    <x-settings-shell heading="Roles &amp; permissions" description="What each role can do, and how much data it can see." active="settings.roles">

    <div
        x-data="{ message: '' }"
        x-on:role-deleted.window="message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @error('delete')
        <x-alert variant="error" class="mb-4">{{ $message }}</x-alert>
    @enderror

    <div class="rounded-xl border border-border bg-card">
        @if ($roles->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <x-lucide-shield-check class="h-6 w-6" aria-hidden="true" />
                </span>
                <p class="text-sm font-medium text-foreground">No roles yet.</p>
                <p class="text-sm text-muted-foreground">Create a role to bundle permissions and set how much data it sees.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Role</th>
                            <th scope="col" class="px-4 py-3 font-medium">Data access</th>
                            <th scope="col" class="px-4 py-3 font-medium">Permissions</th>
                            <th scope="col" class="px-4 py-3 font-medium">Users</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roles as $role)
                            @php($level = DataAccessLevel::tryFrom((string) $role->data_access_level) ?? DataAccessLevel::Own)
                            <tr class="border-b border-border last:border-0" wire:key="role-{{ $role->id }}">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <p class="font-medium text-foreground">{{ $role->name }}</p>
                                        @if ($this->isProtected($role))
                                            <x-status-chip color="amber">Protected</x-status-chip>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-chip :color="$level->color()">{{ $level->label() }}</x-status-chip>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $role->permissions_count }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $role->users_count }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($this->isProtected($role))
                                            <span class="text-xs text-muted-foreground">Always has every permission</span>
                                        @else
                                            @can('update', $role)
                                                <a
                                                    href="{{ route('settings.roles.edit', $role) }}"
                                                    wire:navigate
                                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                                                    aria-label="Edit {{ $role->name }}"
                                                >
                                                    <x-lucide-pencil class="h-4 w-4" aria-hidden="true" />
                                                </a>
                                            @endcan

                                            @can('delete', $role)
                                                <button
                                                    type="button"
                                                    wire:click="delete({{ $role->id }})"
                                                    wire:confirm="Remove the {{ $role->name }} role?"
                                                    wire:loading.attr="disabled"
                                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                                    aria-label="Remove {{ $role->name }}"
                                                >
                                                    <x-lucide-trash-2 class="h-4 w-4" aria-hidden="true" />
                                                </button>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Named slots go last: Blade leaks an output buffer when one precedes
         the default content. See .ai/rules/views.md. --}}
    <x-slot:actions>
        @can('create', Spatie\Permission\Models\Role::class)
            <a href="{{ route('settings.roles.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                <x-lucide-plus class="h-4 w-4" aria-hidden="true" />
                Add role
            </a>
        @endcan
    </x-slot:actions>
    </x-settings-shell>
</div>
