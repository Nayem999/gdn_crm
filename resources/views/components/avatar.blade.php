@props(['user' => null, 'url' => null, 'initials' => null, 'size' => 'md'])

@php
    $sizes = [
        'sm' => 'h-8 w-8 text-xs',
        'md' => 'h-9 w-9 text-sm',
        'lg' => 'h-14 w-14 text-base',
        'xl' => 'h-20 w-20 text-lg',
    ];
    $resolvedUrl = $url ?? $user?->avatarUrl();
    $resolvedInitials = $initials ?? $user?->initials() ?? '?';
    $sizeClasses = $sizes[$size] ?? $sizes['md'];
@endphp

@if ($resolvedUrl)
    <img
        src="{{ $resolvedUrl }}"
        alt="{{ $user?->name ? $user->name.' avatar' : 'Avatar' }}"
        {{ $attributes->class(['shrink-0 rounded-full object-cover', $sizeClasses]) }}
    >
@else
    <span
        aria-hidden="true"
        {{ $attributes->class([
            'flex shrink-0 items-center justify-center rounded-full bg-secondary font-semibold text-secondary-foreground',
            $sizeClasses,
        ]) }}
    >{{ $resolvedInitials }}</span>
@endif
