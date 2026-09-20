{{-- The show/hide handler for every password field, defined once per page.

     It lives in the layout rather than beside the field, and the reason is a
     bug this caused. The field used to publish it itself with
     `@once @push('scripts')`, which works only when the field is in the page's
     first render. It is not, on any screen where the field appears after a
     Livewire update — /settings/meta/connect keeps its token fields behind a
     button — because a Livewire update renders the component alone and pushed
     stacks go nowhere. The markup arrived, the handler never did, and the eye
     button silently did nothing.

     Still deliberately plain JS, and still inline. The field is used on the
     login page as well as inside Livewire components, and a control that stops
     working when Alpine fails to boot or the bundle is stale is a control that
     leaves somebody unable to check what they typed. --}}
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
