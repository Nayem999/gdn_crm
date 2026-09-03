@props(['icon', 'color' => 'slate', 'label' => null])

@php use App\Domain\Shared\UI\ChipPalette; @endphp

<span {{ $attributes->class([ChipPalette::BASE, ChipPalette::classes($color)]) }}>
    <x-icon :name="'lucide-' . ($icon)" class="h-3.5 w-3.5" />
    {{ $label ?? $slot }}
</span>
