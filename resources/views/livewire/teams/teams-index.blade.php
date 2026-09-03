<div>
    <x-settings-shell heading="Teams" description="Departments and the people in them. Sub-teams nest under their parent." active="settings.teams">

    <div
        x-data="{ message: '' }"
        x-on:team-deleted.window="message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="rounded-xl border border-border bg-card">
        <div class="flex flex-wrap items-center gap-3 border-b border-border p-4">
            <div class="relative w-full max-w-xs">
                <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <label for="team-search" class="sr-only">Search teams</label>
                <input
                    id="team-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search teams..."
                    class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                >
            </div>

            <div wire:loading wire:target="search" class="flex items-center gap-2 text-sm text-muted-foreground">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Loading
            </div>
        </div>

        @if ($rows->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <x-lucide-users class="h-6 w-6" aria-hidden="true" />
                </span>
                @if ($search !== '')
                    <p class="text-sm font-medium text-foreground">No teams match “{{ $search }}”.</p>
                    <button type="button" wire:click="clearSearch" class="text-sm font-medium text-accent hover:underline">Clear search</button>
                @else
                    <p class="text-sm font-medium text-foreground">No teams yet.</p>
                    <p class="text-sm text-muted-foreground">Create a department to group people and scope what they can see.</p>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Team</th>
                            <th scope="col" class="px-4 py-3 font-medium">Members</th>
                            <th scope="col" class="px-4 py-3 font-medium">Active here</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody wire:loading.class="opacity-50">
                        @foreach ($rows as $row)
                            @php($team = $row['team'])
                            <tr class="border-b border-border last:border-0" wire:key="team-{{ $team->id }}">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2" style="padding-left: {{ $row['depth'] * 1.25 }}rem">
                                        @if ($row['depth'] > 0)
                                            <x-lucide-corner-down-right class="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                        @endif
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-foreground">{{ $team->name }}</p>
                                            @if ($team->description)
                                                <p class="truncate text-xs text-muted-foreground">{{ $team->description }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-chip color="indigo">{{ $team->users_count }}</x-status-chip>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $team->active_members_count }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('update', $team)
                                            <a
                                                href="{{ route('settings.teams.edit', $team) }}"
                                                wire:navigate
                                                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                                                aria-label="Edit {{ $team->name }}"
                                            >
                                                <x-lucide-pencil class="h-4 w-4" aria-hidden="true" />
                                            </a>
                                        @endcan

                                        @can('delete', $team)
                                            <button
                                                type="button"
                                                wire:click="delete({{ $team->id }})"
                                                wire:confirm="Remove {{ $team->name }}? Sub-teams move to the top level and members lose this as their active team."
                                                wire:loading.attr="disabled"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                                aria-label="Remove {{ $team->name }}"
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
        @endif
    </div>

    {{-- Named slots go last: Blade leaks an output buffer when one precedes
         the default content. See .ai/rules/views.md. --}}
    <x-slot:actions>
        @can('create', App\Models\Team::class)
            <a href="{{ route('settings.teams.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                <x-lucide-plus class="h-4 w-4" aria-hidden="true" />
                Add team
            </a>
        @endcan
    </x-slot:actions>
    </x-settings-shell>
</div>
