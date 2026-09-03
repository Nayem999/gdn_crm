@props([
    'heading' => 'Something went wrong',
    'description' => 'We could not load this list. Please try again.',
    'retry' => null,
])

<div
    {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl border border-destructive/30 bg-destructive/5 px-6 py-14 text-center']) }}
    role="alert"
>
    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
        <x-icon name="lucide-triangle-alert" class="h-6 w-6" />
    </div>

    <h3 class="mt-4 text-base font-semibold text-foreground">{{ $heading }}</h3>
    <p class="mt-1 max-w-sm text-sm text-muted-foreground">{{ $description }}</p>

    @if ($retry)
        <x-button type="button" variant="secondary" class="mt-5" wire:click="{{ $retry }}">
            <x-icon name="lucide-rotate-cw" />
            Try again
        </x-button>
    @endif

    {{ $slot }}
</div>
