@component('mail::message')
# {{ $report?->name ?? 'Your report' }}

@if ($report?->description)
{{ $report->description }}
@endif

This is your {{ strtolower($schedule->frequency()->label()) }} copy, attached as
{{ $schedule->format()->label() }}.

@component('mail::button', ['url' => $report === null ? config('app.url') : route('reports.show', $report)])
Open it in {{ config('app.name') }}
@endcomponent

{{-- Said plainly: the recipients may not have an account, and somebody has to
     know whose figures these are and who to ask to stop them. --}}
@component('mail::subcopy')
These figures are {{ $schedule->owner?->name ?? 'the sender' }}'s view of the data — what they can see, not
necessarily everything. {{ $schedule->owner?->name ?? 'The sender' }} set this up and can stop it.
@endcomponent
@endcomponent
