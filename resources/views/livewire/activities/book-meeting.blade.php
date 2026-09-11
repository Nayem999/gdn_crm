@php
    use App\Domain\Activities\ActivityRelations;
    use App\Domain\Settings\DisplayTime;

    $record = $this->subjectRecord();
    $day = $this->chosenDay();
@endphp

<div>
    @if ($this->canBook())
        <button
            type="button"
            wire:click="$set('open', true)"
            class="inline-flex items-center justify-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
        >
            <x-icon name="lucide-calendar-plus" class="h-4 w-4" />
            Book a meeting
        </button>
    @endif

    @if ($open)
        <div
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="book-meeting-heading"
            x-on:keydown.escape.window="$wire.set('open', false)"
        >
            <div class="mt-8 w-full max-w-2xl rounded-xl border border-border bg-card shadow-lg">
                <div class="flex items-start justify-between gap-4 border-b border-border p-4">
                    <div>
                        <h2 id="book-meeting-heading" class="text-lg font-semibold text-foreground">Book a meeting</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            With {{ ActivityRelations::label($record) }} &middot;
                            {{ DisplayTime::timezone() }} time
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('open', false)"
                        class="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        aria-label="Close"
                    >
                        <x-icon name="lucide-x" class="h-4 w-4" />
                    </button>
                </div>

                <form wire:submit="book" class="space-y-4 p-4">
                    <div>
                        <x-form.label for="book-subject" required>What is it about</x-form.label>
                        <x-form.input
                            id="book-subject"
                            wire:model="subject"
                            placeholder="Renewal discussion"
                            class="mt-1"
                        />
                        <x-form.error for="subject" class="mt-1" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-form.label for="book-length">How long</x-form.label>
                            <x-select
                                name="book_minutes"
                                :options="$this->lengthOptions()"
                                :selected="(string) $minutes"
                                wire:model.live="minutes"
                                class="mt-1"
                            />
                        </div>

                        @if ($this->canAssign())
                            <div>
                                <x-form.label for="book-owner">Whose diary</x-form.label>
                                <x-select
                                    name="book_owner"
                                    :options="$this->ownerOptions()"
                                    :selected="(string) $ownerId"
                                    wire:model.live="ownerId"
                                    class="mt-1"
                                />
                            </div>
                        @endif
                    </div>

                    {{-- The day being looked at. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="step(-1)"
                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            aria-label="Previous day"
                        >
                            <x-icon name="lucide-chevron-left" class="h-4 w-4" />
                        </button>

                        <input
                            type="date"
                            wire:model.live="date"
                            aria-label="Day"
                            class="h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground"
                        />

                        <button
                            type="button"
                            wire:click="step(1)"
                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            aria-label="Next day"
                        >
                            <x-icon name="lucide-chevron-right" class="h-4 w-4" />
                        </button>

                        <span class="text-sm font-medium text-foreground">{{ $day->format('l j F Y') }}</span>

                        <span wire:loading wire:target="date,step,minutes,ownerId" class="text-muted-foreground">
                            <x-icon name="lucide-loader-circle" class="h-4 w-4 animate-spin" />
                        </span>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-medium text-foreground">Free slots</p>

                        @if (! $this->isWorkingDay())
                            <p class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                                {{ $day->format('l') }} is outside the working week.
                                Scheduling &rarr; Working week sets which days are bookable.
                            </p>
                        @elseif ($this->slots === [])
                            <p class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                                A {{ $minutes }}-minute meeting does not fit in the working day.
                            </p>
                        @else
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4" wire:loading.class="opacity-50">
                                @foreach ($this->slots as $option)
                                    <button
                                        type="button"
                                        wire:key="slot-{{ $option->value() }}"
                                        wire:click="chooseSlot('{{ $option->value() }}')"
                                        @disabled(! $option->isFree())
                                        @class([
                                            'rounded-lg border px-2 py-2 text-sm font-medium tabular-nums transition-colors',
                                            'border-accent bg-accent text-accent-foreground' => $slot === $option->value(),
                                            'border-border text-foreground hover:bg-muted' => $slot !== $option->value() && $option->isFree(),
                                            'cursor-not-allowed border-border bg-muted/50 text-muted-foreground line-through' => ! $option->isFree(),
                                        ])
                                        title="{{ $option->isFree() ? $option->rangeLabel() : $option->conflictLabel() }}"
                                    >
                                        {{ $option->label() }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <x-form.error for="slot" class="mt-2" />
                    </div>

                    <div>
                        <x-form.label for="book-location">Where</x-form.label>
                        <x-form.input
                            id="book-location"
                            wire:model="location"
                            placeholder="Their office, a video call, a phone number…"
                            class="mt-1"
                        />
                        <x-form.error for="location" class="mt-1" />
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-border pt-4">
                        <button
                            type="button"
                            wire:click="$set('open', false)"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="book"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90 disabled:opacity-60"
                        >
                            <x-icon name="lucide-loader-circle" class="h-4 w-4 animate-spin" wire:loading wire:target="book" />
                            Book it
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
