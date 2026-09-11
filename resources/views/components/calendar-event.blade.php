@props(['event'])

@php
    use App\Domain\Shared\UI\ChipPalette;

    /** @var \App\Domain\Activities\Calendar\CalendarEvent $event */
    $canOpen = auth()->user()?->can('update', $event->activity) ?? false;
    $tag = $canOpen ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($canOpen)
        href="{{ route('activities.edit', $event->activity->id) }}"
        wire:navigate
    @endif
    {{ $attributes->merge([
        'class' => 'block overflow-hidden rounded px-1.5 py-0.5 text-left text-xs leading-tight transition-opacity hover:opacity-80 '
            . ChipPalette::classes($event->color()),
    ]) }}
    title="{{ $event->timeLabel() }} — {{ $event->title() }}"
>
    <span class="flex items-center gap-1">
        <x-icon :name="'lucide-' . $event->icon()" class="h-3 w-3 shrink-0" />

        @unless ($event->allDay)
            <span class="shrink-0 tabular-nums opacity-80">{{ $event->timeLabel() }}</span>
        @endunless

        <span @class(['truncate font-medium', 'line-through opacity-60' => $event->isCompleted()])>
            {{ $event->title() }}
        </span>

        @if ($event->isOverdue())
            {{-- Colour is never the only signal: the chip is already tinted by
                 type, so lateness needs a mark of its own. --}}
            <x-icon name="lucide-alert-circle" class="h-3 w-3 shrink-0" aria-label="Overdue" />
        @endif
    </span>
</{{ $tag }}>
