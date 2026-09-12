<x-mail::message>
# Email is working

This is a test message from {{ config('app.name') }}, sent through **{{ $providerLabel }}** by {{ $sentBy }}.

If you are reading it, the provider's credentials are correct and mail is leaving the application.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
