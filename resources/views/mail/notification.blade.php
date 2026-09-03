<x-mail::message>
{{-- Escaped on purpose: an admin-authored template is text, never markup. --}}
{{ $body }}

@if ($url)
<x-mail::button :url="$url">
Open in {{ config('app.name') }}
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
