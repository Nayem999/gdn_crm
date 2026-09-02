@props(['type' => 'submit', 'variant' => 'primary', 'full' => false])

@php
    $variants = [
        'primary' => 'bg-primary text-primary-foreground hover:opacity-90',
        'accent' => 'bg-accent text-accent-foreground hover:opacity-90',
        'secondary' => 'border border-border bg-card text-foreground hover:bg-muted',
        'destructive' => 'bg-destructive text-destructive-foreground hover:opacity-90',
    ];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors',
        'focus:outline-none focus:ring-2 focus:ring-accent/40 disabled:cursor-not-allowed disabled:opacity-60',
        $variants[$variant] ?? $variants['primary'],
        'w-full' => $full,
    ]) }}
>
    {{ $slot }}
</button>
