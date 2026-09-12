<x-mail::message>
# {{ $quote->reference() }}

@if ($intro !== '')
{{ $intro }}
@else
Please find our quote attached.
@endif

<x-mail::panel>
**Total:** {{ number_format((float) $quote->total, 2) }}
@if ($quote->valid_until)

Valid until {{ $quote->valid_until->toFormattedDateString() }}.
@endif
</x-mail::panel>

If anything needs changing, reply to this message and we will send a revised version.

Thanks,<br>
{{ $quote->owner?->name ?? config('app.name') }}
</x-mail::message>
