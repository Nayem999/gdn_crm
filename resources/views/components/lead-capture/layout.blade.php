{{-- A standalone page, not the app shell: it renders inside an iframe on
     somebody else's site, so it carries no navigation, no session chrome and
     no Livewire. --}}
@props(['form', 'title' => null])

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Not indexed: a capture form is for the page that embeds it. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? $form->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-transparent p-4 text-foreground antialiased">
    <div class="mx-auto w-full max-w-xl">
        {{ $slot }}
    </div>
</body>
</html>
