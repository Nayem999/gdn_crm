@props(['invalid' => false])

{{-- Deliberately plain JS rather than Alpine: this control has to keep working
     even if Alpine hasn't booted (bad asset path, blocked script, stale
     bundle), because a dead Alpine would silently leave the button inert. The
     handler is inline so it needs no compiled bundle either. --}}
@once
    @push('scripts')
        <script>
            window.togglePasswordField = function (button) {
                var input = button.parentElement.querySelector('input');
                if (!input) {
                    return;
                }

                var reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';

                button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');

                var showIcon = button.querySelector('[data-password-icon="show"]');
                var hideIcon = button.querySelector('[data-password-icon="hide"]');

                if (showIcon && hideIcon) {
                    showIcon.style.display = reveal ? 'none' : '';
                    hideIcon.style.display = reveal ? '' : 'none';
                }
            };
        </script>
    @endpush
@endonce

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
