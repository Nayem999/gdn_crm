@props(['type' => 'text', 'invalid' => false])

<input
    type="{{ $type }}"
    @if ($invalid) aria-invalid="true" @endif
    {{ $attributes->class([
        'w-full rounded-lg border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground',
        'focus:outline-none focus:ring-2',
        'border-destructive focus:border-destructive focus:ring-destructive/40' => $invalid,
        'border-border focus:border-accent focus:ring-accent/40' => ! $invalid,
    ]) }}
>
