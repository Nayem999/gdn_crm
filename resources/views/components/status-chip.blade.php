@props(['color' => null, 'status' => null, 'dot' => false])

@php
    use App\Domain\Shared\UI\ChipPalette;

    // A status enum carries its own colour and label, so callers can pass the
    // enum itself instead of restating both at every call site.
    $resolvedColor = $color
        ?? (is_object($status) && method_exists($status, 'color') ? $status->color() : null)
        ?? 'slate';

    $text = trim((string) $slot) !== ''
        ? null
        : (is_object($status) && method_exists($status, 'label') ? $status->label() : null);
@endphp

<span {{ $attributes->class([ChipPalette::BASE, ChipPalette::classes($resolvedColor)]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ ChipPalette::dotClasses($resolvedColor) }}" aria-hidden="true"></span>
    @endif

    {{ $text ?? $slot }}
</span>
