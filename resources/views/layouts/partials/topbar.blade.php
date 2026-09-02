<header class="sticky top-0 z-30 flex h-topbar shrink-0 items-center gap-4 border-b border-border bg-card px-4 sm:px-6">
    <button
        type="button"
        @click="sidebarOpen = true"
        class="-ml-1 flex h-10 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground lg:hidden"
        aria-label="Open sidebar"
    >
        <x-lucide-menu class="h-5 w-5" aria-hidden="true" />
    </button>

    <div class="flex flex-1 items-center gap-3">
        <label for="global-search" class="sr-only">Search</label>
        <div class="relative w-full max-w-md">
            <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <input
                id="global-search"
                type="search"
                placeholder="Search leads, contacts, deals..."
                class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
            >
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button
            type="button"
            @click="
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            "
            class="flex h-10 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
            aria-label="Toggle dark mode"
        >
            <x-lucide-sun class="h-5 w-5 dark:hidden" aria-hidden="true" />
            <x-lucide-moon class="hidden h-5 w-5 dark:block" aria-hidden="true" />
        </button>

        <button
            type="button"
            class="relative flex h-10 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
            aria-label="Notifications"
        >
            <x-lucide-bell class="h-5 w-5" aria-hidden="true" />
        </button>

        @auth
            @php($user = auth()->user())
            <div class="relative ml-1" x-data="{ open: false }" x-on:keydown.escape="open = false">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    :aria-expanded="open"
                    aria-haspopup="menu"
                    class="flex rounded-full focus:outline-none focus:ring-2 focus:ring-accent/40"
                    aria-label="Account menu"
                >
                    <x-avatar :user="$user" size="md" />
                </button>

                <div
                    x-show="open"
                    x-cloak
                    x-transition
                    x-on:click.outside="open = false"
                    role="menu"
                    class="absolute right-0 z-40 mt-2 w-60 overflow-hidden rounded-lg border border-border bg-card shadow-lg"
                >
                    <div class="border-b border-border px-4 py-3">
                        <p class="truncate text-sm font-semibold text-card-foreground">{{ $user->name }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ $user->email }}</p>
                    </div>

                    <a
                        href="{{ route('profile') }}"
                        role="menuitem"
                        class="flex items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted"
                    >
                        <x-lucide-user class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                        My profile
                    </a>

                    <a
                        href="{{ route('settings.company') }}"
                        role="menuitem"
                        class="flex items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted"
                    >
                        <x-lucide-building-2 class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                        Company profile
                    </a>

                    @can('viewAny', App\Models\User::class)
                        <a
                            href="{{ route('settings.users') }}"
                            role="menuitem"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted"
                        >
                            <x-lucide-users class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                            Users
                        </a>
                    @endcan

                    @can('viewAny', App\Models\Team::class)
                        <a
                            href="{{ route('settings.teams') }}"
                            role="menuitem"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-foreground hover:bg-muted"
                        >
                            <x-lucide-network class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                            Teams
                        </a>
                    @endcan

                    <form method="POST" action="{{ route('logout') }}" class="border-t border-border">
                        @csrf
                        <button
                            type="submit"
                            role="menuitem"
                            class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-foreground hover:bg-muted"
                        >
                            <x-lucide-log-out class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        @endauth
    </div>
</header>
