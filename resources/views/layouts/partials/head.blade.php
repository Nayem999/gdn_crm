<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ ($title ?? null) ? $title.' - '.config('app.name') : config('app.name') }}</title>

{{-- Applied before first paint so the correct theme is never flashed over.

     wire:navigate copies the incoming document's <html> attributes over the
     live ones, and the server never renders the dark class, so every SPA
     navigation dropped the theme and made the topbar toggle look broken.
     Re-applying on livewire:navigated puts it back as part of the same swap. --}}
<script>
    (function () {
        function applyStoredTheme() {
            var stored = localStorage.getItem('theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            document.documentElement.classList.toggle('dark', stored === 'dark' || (!stored && prefersDark));
        }

        applyStoredTheme();

        document.addEventListener('livewire:navigated', applyStoredTheme);
    })();
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('styles')
@livewireStyles
