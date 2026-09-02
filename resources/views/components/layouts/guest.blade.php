@props(['title' => null, 'heading' => null, 'subheading' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('layouts.partials.head', ['title' => $title])
    </head>
    <body class="h-full bg-background font-sans text-foreground antialiased">
        <div class="flex min-h-full flex-col items-center justify-center px-4 py-12 sm:px-6">
            <div class="w-full max-w-md">
                <div class="mb-8 flex items-center justify-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                        <x-lucide-building-2 class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <span class="text-lg font-semibold text-foreground">{{ config('app.name') }}</span>
                </div>

                <div class="rounded-xl border border-border bg-card p-6 shadow-sm sm:p-8">
                    @if ($heading)
                        <h1 class="text-xl font-semibold text-card-foreground">{{ $heading }}</h1>
                    @endif

                    @if ($subheading)
                        <p class="mt-1 text-sm text-muted-foreground">{{ $subheading }}</p>
                    @endif

                    <div class="{{ $heading || $subheading ? 'mt-6' : '' }}">
                        {{ $slot }}
                    </div>
                </div>

                @isset($footer)
                    <div class="mt-6 text-center text-sm text-muted-foreground">
                        {{ $footer }}
                    </div>
                @endisset
            </div>
        </div>

        @stack('scripts')
        @livewireScripts
    </body>
</html>
