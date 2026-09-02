@props(['invalid' => false])

<div class="relative" x-data="{ show: false }">
    <x-form.input
        type="password"
        x-ref="input"
        :invalid="$invalid"
        {{ $attributes->class(['pr-11']) }}
    />

    {{-- The type is set straight on the element rather than through x-bind:type so
         the toggle never depends on Alpine's binding flush order. --}}
    <button
        type="button"
        x-on:click="show = ! show; $refs.input.type = show ? 'text' : 'password'"
        x-bind:aria-label="show ? 'Hide password' : 'Show password'"
        x-bind:aria-pressed="show ? 'true' : 'false'"
        aria-label="Show password"
        aria-pressed="false"
        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-muted-foreground transition-colors hover:text-foreground focus:outline-none focus:ring-2 focus:ring-accent/40"
    >
        <x-lucide-eye class="pointer-events-none h-4 w-4" x-show="! show" aria-hidden="true" />
        <x-lucide-eye-off class="pointer-events-none h-4 w-4" x-show="show" x-cloak aria-hidden="true" />
    </button>
</div>
