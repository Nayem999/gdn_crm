@props(['color' => null, 'status' => null, 'dot' => false, 'label' => null])

@php
    use App\Domain\Shared\UI\ChipPalette;

    // A status enum carries its own colour and label, so callers can pass the
    // enum itself instead of restating both at every call site.
    $resolvedColor = $color
        ?? (is_object($status) && method_exists($status, 'color') ? $status->color() : null)
        ?? 'slate';

    /**
     * Three ways to say what the chip reads, in order of specificity: the slot,
     * an explicit label, or the status enum's own.
     *
     * `label` is declared rather than left to fall through to $attributes, and
     * that is not tidiness. An undeclared prop becomes an HTML attribute, so
     * `<x-status-chip label="Connected" />` rendered an **empty** chip carrying
     * a stray `label="Connected"` — visibly blank, with nothing in the markup
     * that looked like a mistake. It is the obvious way to call this component,
     * so it has to be one that works; two shipped screens had already written it.
     */
    $text = trim((string) $slot) !== ''
        ? null
        : ($label ?? (is_object($status) && method_exists($status, 'label') ? $status->label() : null));
@endphp

<span {{ $attributes->class([ChipPalette::BASE, ChipPalette::classes($resolvedColor)]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ ChipPalette::dotClasses($resolvedColor) }}" aria-hidden="true"></span>
    @endif

    {{ $text ?? $slot }}
</span>
