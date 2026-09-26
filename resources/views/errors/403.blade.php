{{--
    The exception's own message when it has one — a policy says "This action is
    unauthorized.", a suspended workspace says why — since a 403 is written for
    the person refused.
--}}
<x-error-page
    code="403"
    title="Not permitted"
    :message="($exception?->getMessage() ?: 'This action is unauthorized.')"
    icon="lucide-shield-x"
>
    Your role does not allow this. If you need it, ask your administrator to
    change your access.
</x-error-page>
