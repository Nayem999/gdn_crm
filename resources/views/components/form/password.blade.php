@props(['invalid' => false])

{{-- The `togglePasswordField` handler is in the layout, not here. It used to
     be pushed from this file with `@once @push('scripts')`, which silently did
     nothing for a field that appears after a Livewire update — see
     components/form/password-script.blade.php. --}}

<div class="relative">
    <x-form.input
        type="password"
        :invalid="$invalid"
        {{ $attributes->class(['pr-11']) }}
    />

    <button
        type="button"
        onclick="togglePasswordField(this)"
        aria-label="Show password"
        aria-pressed="false"
        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-muted-foreground transition-colors hover:text-foreground focus:outline-none focus:ring-2 focus:ring-accent/40"
    >
        {{-- pointer-events-none so the click always lands on the button, and
             inline display so the initial state needs no JS or CSS layer. --}}
        <x-lucide-eye
            data-password-icon="show"
            class="pointer-events-none h-4 w-4"
            aria-hidden="true"
        />
        <x-lucide-eye-off
            data-password-icon="hide"
            style="display: none"
            class="pointer-events-none h-4 w-4"
            aria-hidden="true"
        />
    </button>
</div>
