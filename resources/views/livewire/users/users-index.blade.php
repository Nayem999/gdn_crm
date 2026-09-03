<div>
    <x-settings-shell heading="Users" description="People with access to this CRM." active="settings.users">

    <div
        x-data="{ message: '' }"
        x-on:user-deleted.window="message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    @error('delete')
        <x-alert variant="error" class="mb-4">{{ $message }}</x-alert>
    @enderror

    <div class="rounded-xl border border-border bg-card">
        <div class="flex flex-wrap items-center gap-3 border-b border-border p-4">
            <div class="relative w-full max-w-xs">
                <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <label for="user-search" class="sr-only">Search users</label>
                <input
                    id="user-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search name or email..."
                    class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
            </div>

            <div wire:loading wire:target="search, perPage, gotoPage, nextPage, previousPage" class="flex items-center gap-2 text-sm text-muted-foreground">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Loading
            </div>

            <div class="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                <label for="per-page">Per page</label>
                <select
                    id="per-page"
                    wire:model.live="perPage"
                    class="rounded-lg border border-border bg-background px-2 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        @if ($users->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <x-lucide-users class="h-6 w-6" aria-hidden="true" />
                </span>
                @if ($search !== '')
                    <p class="text-sm font-medium text-foreground">No users match “{{ $search }}”.</p>
                    <button type="button" wire:click="clearSearch" class="text-sm font-medium text-accent hover:underline">Clear search</button>
                @else
                    <p class="text-sm font-medium text-foreground">No users yet.</p>
                    <p class="text-sm text-muted-foreground">Invite a colleague to get started.</p>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Name</th>
                            <th scope="col" class="px-4 py-3 font-medium">Role</th>
                            <th scope="col" class="px-4 py-3 font-medium">Team</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="opacity-50">
                        @foreach ($users as $user)
                            <tr class="border-b border-border last:border-0" wire:key="user-{{ $user->id }}">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <x-avatar :user="$user" size="sm" />
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-foreground">{{ $user->name }}</p>
                                            <p class="truncate text-xs text-muted-foreground">{{ $user->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @forelse ($user->roles as $role)
                                        <x-status-chip color="indigo">{{ $role->name }}</x-status-chip>
                                    @empty
                                        <span class="text-xs text-muted-foreground">None</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ $user->currentTeam?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('update', $user)
                                            <a
                                                href="{{ route('settings.users.edit', $user) }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                                                aria-label="Edit {{ $user->name }}"
                                            >
                                                <x-lucide-pencil class="h-4 w-4" aria-hidden="true" />
                                            </a>
                                        @endcan

                                        @can('delete', $user)
                                            <button
                                                type="button"
                                                wire:click="delete({{ $user->id }})"
                                                wire:confirm="Remove {{ $user->name }}? They will lose access immediately."
                                                wire:loading.attr="disabled"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                                aria-label="Remove {{ $user->name }}"
                                            >
                                                <x-lucide-trash-2 class="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="border-t border-border p-4">
                    {{ $users->onEachSide(1)->links() }}
                </div>
            @endif
        @endif
    </div>

    {{-- Named slots go last: Blade leaks an output buffer when one precedes
         the default content. See .ai/rules/views.md. --}}
    <x-slot:actions>
        @can('invite', App\Models\User::class)
            <a href="{{ route('settings.users.invite') }}" class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                <x-lucide-mail class="h-4 w-4" aria-hidden="true" />
                Invite user
            </a>
        @endcan

        @can('create', App\Models\User::class)
            <a href="{{ route('settings.users.create') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                <x-lucide-plus class="h-4 w-4" aria-hidden="true" />
                Add user
            </a>
        @endcan
    </x-slot:actions>
    </x-settings-shell>
</div>
