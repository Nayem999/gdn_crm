@props(['invalid' => false])

<div class="relative" x-data="{ show: false }">
    <x-form.input
        type="password"
        x-bind:type="show ? 'text' : 'password'"
        :invalid="$invalid"
        {{ $attributes->class(['pr-11']) }}
    />

    <button
        type="button"
        x-on:click="show = ! show"
        x-bind:aria-label="show ? 'Hide password' : 'Show password'"
        x-bind:aria-pressed="show ? 'true' : 'false'"
        aria-label="Show password"
        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-muted-foreground transition-colors hover:text-foreground focus:outline-none focus:ring-2 focus:ring-accent/40"
    >
        <x-lucide-eye class="h-4 w-4" x-show="! show" aria-hidden="true" />
        <x-lucide-eye-off class="h-4 w-4" x-show="show" x-cloak aria-hidden="true" />
    </button>
</div>
