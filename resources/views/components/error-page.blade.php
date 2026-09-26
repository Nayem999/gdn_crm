{{--
    The shell for an error page: the status code, what it means, and a way on.

    Built on the guest layout rather than the app shell: an error can be
    rendered before the session has started — a 404 for a URL that matches no
    route never runs the web middleware — so nothing here may assume a
    signed-in user, a sidebar or a workspace.
--}}
@props([
    'code',
    'title',
    'message',
    'icon' => 'lucide-circle-alert',
])

<x-layouts.guest :title="$title">
    <div class="text-center">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <x-icon :name="$icon" class="h-6 w-6" aria-hidden="true" />
        </span>

        <p class="mt-4 text-5xl font-bold tracking-tight text-foreground">{{ $code }}</p>
        <h1 class="mt-2 text-lg font-semibold text-card-foreground">{{ $message }}</h1>

        <div class="mx-auto mt-2 max-w-sm text-sm text-muted-foreground">
            {{ $slot }}
        </div>

        <div class="mt-6 flex flex-col-reverse items-stretch justify-center gap-2 sm:flex-row sm:items-center">
            <button type="button" onclick="history.back()"
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium text-foreground hover:bg-muted">
                <x-icon name="lucide-arrow-left" class="h-4 w-4" />
                Go back
            </button>
            <a href="{{ route('dashboard') }}"
               class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                <x-icon name="lucide-house" class="h-4 w-4" />
                Go to the dashboard
            </a>
        </div>
    </div>
</x-layouts.guest>
