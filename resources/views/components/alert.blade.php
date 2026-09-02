@props(['variant' => 'info'])

@php
    $variants = [
        'info' => ['class' => 'border-accent/30 bg-accent/10 text-accent', 'icon' => 'lucide-info'],
        'success' => ['class' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', 'icon' => 'lucide-circle-check'],
        'error' => ['class' => 'border-destructive/30 bg-destructive/10 text-destructive', 'icon' => 'lucide-circle-alert'],
    ];
    $resolved = $variants[$variant] ?? $variants['info'];
@endphp

<div
    role="{{ $variant === 'error' ? 'alert' : 'status' }}"
    {{ $attributes->class(['flex items-start gap-2 rounded-lg border px-4 py-3 text-sm', $resolved['class']]) }}
>
    <x-dynamic-component :component="$resolved['icon']" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
    <div>{{ $slot }}</div>
</div>
