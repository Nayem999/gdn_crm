@php
    use App\Domain\Activities\Calendar\CalendarScale;
    use App\Domain\Shared\UI\ChipPalette;

    $grid = $this->grid;
    $period = $grid->period;
    $scale = $this->currentScale();

    /** Pixels per hour in the week and day grids. */
    $hourHeight = 48;
@endphp

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300">
                <x-icon name="lucide-calendar-days" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Calendar</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Tasks, calls and meetings, on {{ \App\Domain\Settings\DisplayTime::timezone() }} time.
                </p>
            </div>
        </div>

        @if ($this->canCreate())
            <a
                href="{{ route('activities.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                Add activity
            </a>
        @endif
    </div>

    {{-- Toolbar: where we are, how to move, and what is shown. --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <div class="flex items-center rounded-lg border border-border">
                <button
                    type="button"
                    wire:click="previous"
                    class="flex h-9 w-9 items-center justify-center rounded-l-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="Previous {{ $scale->label() }}"
                >
                    <x-icon name="lucide-chevron-left" class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    wire:click="today"
                    class="h-9 border-x border-border px-3 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                >
                    Today
                </button>
                <button
                    type="button"
                    wire:click="next"
                    class="flex h-9 w-9 items-center justify-center rounded-r-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="Next {{ $scale->label() }}"
                >
                    <x-icon name="lucide-chevron-right" class="h-4 w-4" />
                </button>
            </div>

            <h2 class="text-lg font-semibold text-foreground" wire:loading.class="opacity-50">
                {{ $period->label() }}
            </h2>

            <span wire:loading class="text-muted-foreground">
                <x-icon name="lucide-loader-circle" class="h-4 w-4 animate-spin" />
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="$toggle('mineOnly')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                    'border-accent bg-accent/10 text-accent' => $mineOnly,
                    'border-border text-muted-foreground hover:text-foreground' => ! $mineOnly,
                ])
                aria-pressed="{{ $mineOnly ? 'true' : 'false' }}"
            >
                Mine
            </button>

            <button
                type="button"
                wire:click="$toggle('showCompleted')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                    'border-accent bg-accent/10 text-accent' => ! $showCompleted,
                    'border-border text-muted-foreground hover:text-foreground' => $showCompleted,
                ])
                aria-pressed="{{ $showCompleted ? 'false' : 'true' }}"
            >
                Hide done
            </button>

            <div class="w-40">
                <x-select
                    name="calendar_type"
                    :options="$this->typeOptions()"
                    :selected="$type"
                    placeholder="Any kind"
                    clearable
                    wire:model.live="type"
                />
            </div>

            <div class="flex items-center rounded-lg border border-border p-0.5">
                @foreach ($this->scales() as $option)
                    <button
                        type="button"
                        wire:click="setScale('{{ $option->value }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm font-medium transition-colors',
                            'bg-accent/10 text-accent' => $scale === $option,
                            'text-muted-foreground hover:text-foreground' => $scale !== $option,
                        ])
                        aria-pressed="{{ $scale === $option ? 'true' : 'false' }}"
                    >
                        <x-icon :name="'lucide-' . $option->icon()" class="h-4 w-4" />
                        <span class="hidden sm:inline">{{ $option->label() }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @if ($grid->truncated)
        <x-alert variant="warning" class="mb-4">
            This period has more than {{ number_format(\App\Domain\Activities\Calendar\CalendarBuilder::MAX_EVENTS) }}
            activities. Narrow the filters, or look at a shorter period, to see them all.
        </x-alert>
    @endif

    @if ($scale === CalendarScale::Month)
        {{-- Month: one cell per day, listing what is on it. --}}
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="grid grid-cols-7 border-b border-border bg-muted/40">
                @foreach ($period->weekdayNames() as $name)
                    <div class="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ $name }}
                    </div>
                @endforeach
            </div>

            @foreach ($grid->weeks() as $week)
                <div class="grid grid-cols-7 border-b border-border last:border-b-0">
                    @foreach ($week as $day)
                        <div
                            wire:key="cell-{{ $day->key() }}"
                            @class([
                                'min-h-28 border-r border-border p-1.5 last:border-r-0',
                                'bg-muted/30' => ! $day->isInFocus,
                            ])
                        >
                            <button
                                type="button"
                                wire:click="openDay('{{ $day->key() }}')"
                                @class([
                                    'mb-1 flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium transition-colors',
                                    'bg-accent text-white' => $day->isToday(),
                                    'text-foreground hover:bg-muted' => ! $day->isToday() && $day->isInFocus,
                                    'text-muted-foreground hover:bg-muted' => ! $day->isToday() && ! $day->isInFocus,
                                ])
                                aria-label="Open {{ $day->date->format('j F Y') }}"
                            >
                                {{ $day->date->format('j') }}
                            </button>

                            <div class="space-y-1">
                                @foreach (array_slice($day->events, 0, 3) as $event)
                                    <x-calendar-event :event="$event" />
                                @endforeach

                                @if ($day->count() > 3)
                                    <button
                                        type="button"
                                        wire:click="openDay('{{ $day->key() }}')"
                                        class="w-full px-1 text-left text-xs font-medium text-muted-foreground hover:text-foreground"
                                    >
                                        +{{ $day->count() - 3 }} more
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @else
        {{-- Week and day: an hour grid, with the all-day entries above it. --}}
        @php($days = $grid->list())

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="flex border-b border-border bg-muted/40">
                <div class="w-16 shrink-0 border-r border-border"></div>
                @foreach ($days as $day)
                    <div class="flex-1 border-r border-border px-2 py-2 text-center last:border-r-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ $day->date->format('D') }}
                        </p>
                        <p @class([
                            'mt-0.5 text-sm font-semibold',
                            'text-accent' => $day->isToday(),
                            'text-foreground' => ! $day->isToday(),
                        ])>{{ $day->date->format('j') }}</p>
                    </div>
                @endforeach
            </div>

            {{-- The all-day strip. Always rendered so the columns below it do
                 not shift about as entries come and go. --}}
            <div class="flex border-b border-border">
                <div class="flex w-16 shrink-0 items-center justify-end border-r border-border px-2 py-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                    All day
                </div>
                @foreach ($days as $day)
                    <div class="min-h-9 flex-1 space-y-1 border-r border-border p-1 last:border-r-0">
                        @foreach ($day->allDayEvents() as $event)
                            <x-calendar-event :event="$event" />
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="max-h-[32rem] overflow-y-auto">
                <div class="flex">
                    {{-- The hour ruler. --}}
                    <div class="w-16 shrink-0 border-r border-border">
                        @foreach ($this->hours() as $hour)
                            <div class="relative border-b border-border/60 last:border-b-0" style="height: {{ $hourHeight }}px">
                                <span class="absolute -top-2 right-2 text-[11px] tabular-nums text-muted-foreground">
                                    {{ $hour === 0 ? '' : sprintf('%02d:00', $hour) }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    @foreach ($days as $day)
                        <div class="relative flex-1 border-r border-border last:border-r-0" wire:key="col-{{ $day->key() }}">
                            @foreach ($this->hours() as $hour)
                                <div class="border-b border-border/60 last:border-b-0" style="height: {{ $hourHeight }}px"></div>
                            @endforeach

                            {{-- Blocks are positioned from midnight, in minutes,
                                 so a 14:30 start sits half way down the 14:00
                                 row rather than at the top of it. --}}
                            @foreach ($day->timedEvents() as $event)
                                <div
                                    class="absolute inset-x-1"
                                    style="top: {{ $event->offsetMinutes() * $hourHeight / 60 }}px; min-height: 18px; height: {{ max(18, $event->durationMinutes() * $hourHeight / 60) }}px"
                                    wire:key="event-{{ $event->activity->id }}"
                                >
                                    <x-calendar-event :event="$event" class="h-full" />
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($grid->isEmpty())
        <x-empty-state
            class="mt-4"
            icon="calendar-days"
            heading="Nothing in this {{ strtolower($scale->label()) }}"
            description="Move to another period, or add a call, meeting or task."
        />
    @endif
</div>
