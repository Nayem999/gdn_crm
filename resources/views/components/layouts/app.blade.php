@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full overflow-hidden">
    <head>
        @include('layouts.partials.head', ['title' => $title])
    </head>
    <body class="h-full overflow-hidden bg-background font-sans text-foreground antialiased">
        {{-- h-screen + overflow-hidden here, and min-h-0 on the flex children,
             keep <main> the only scroll container. Without min-h-0 a flex item's
             default min-height:auto lets the content push past the viewport and
             the document grows a second scrollbar. --}}
        <div x-data="{ sidebarOpen: false }" class="flex h-screen overflow-hidden">
            @include('layouts.partials.sidebar')

            <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                @include('layouts.partials.topbar')

                <main class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @stack('scripts')
        @livewireScripts
    </body>
</html>
