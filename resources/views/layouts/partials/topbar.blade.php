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

        <div class="ml-1 flex h-9 w-9 items-center justify-center rounded-full bg-secondary text-sm font-semibold text-secondary-foreground">
            {{ Str::of(auth()->user()->name ?? 'Guest')->explode(' ')->map(fn ($part) => Str::substr($part, 0, 1))->join('') }}
        </div>
    </div>
</header>
