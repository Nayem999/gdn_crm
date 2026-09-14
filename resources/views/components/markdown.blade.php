@props(['html'])

{{-- Markup this application generated itself, from markdown it converted with
     raw HTML stripped. Printed unescaped because escaping it would show the
     reader the tags instead of the document — which is only ever correct when
     the caller controls what was converted, never for a record's own text. --}}
<div {{ $attributes->merge(['class' => 'doc-body']) }}>{!! $html !!}</div>
