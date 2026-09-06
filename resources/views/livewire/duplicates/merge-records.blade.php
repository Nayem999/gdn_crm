@php
    use App\Domain\Shared\UI\ChipPalette;

    $source = $this->source();
    $record = $this->record();
    $candidates = $this->candidates();
    $other = $this->other();
    $survivor = $this->survivor();
    $loser = $this->loser();
    $conflicts = $this->conflictingFields();
@endphp

<div>
    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 6000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <template x-if="tone === 'error'">
            <x-alert variant="error"><span x-text="message"></span></x-alert>
        </template>
        <template x-if="tone !== 'error'">
            <x-alert variant="success"><span x-text="message"></span></x-alert>
        </template>
    </div>

    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ $source->showRoute($record) }}" wire:navigate class="hover:text-foreground">
            {{ $source->label($record) }}
        </a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">Merge duplicates</span>
    </nav>

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-foreground">Merge duplicates</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Nothing is thrown away: the record you merge away is kept, marked as merged, and its
            history stays readable.
        </p>
    </div>

    @if ($candidates === [])
        <x-empty-state
            icon="copy-check"
            heading="No duplicates found"
            description="Nothing else matches this record's email, phone number or company."
        >
            <x-slot:actions>
                <a href="{{ $source->showRoute($record) }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted">
                    <x-icon name="lucide-arrow-left" />
                    Back to {{ $source->label($record) }}
                </a>
            </x-slot:actions>
        </x-empty-state>
    @else
        <section class="mb-6 rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Which record is this a duplicate of?</h2>

            <ul class="mt-4 space-y-2">
                @foreach ($candidates as $match)
                    @php $candidateId = (int) $match->record->getKey(); @endphp

                    <li wire:key="candidate-{{ $candidateId }}">
                        <button
                            type="button"
                            wire:click="selectCounterpart({{ $candidateId }})"
                            @class([
                                'flex w-full flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3 text-left transition-colors',
                                'border-accent bg-accent/5' => $otherId === $candidateId,
                                'border-border hover:bg-muted' => $otherId !== $candidateId,
                            ])
                            aria-pressed="{{ $otherId === $candidateId ? 'true' : 'false' }}"
                        >
                            <span>
                                <span class="block font-medium text-foreground">{{ $source->label($match->record) }}</span>
                                <span class="block text-sm text-muted-foreground">{{ $match->summary() }}</span>
                            </span>

                            @if ($match->confidence())
                                <x-status-chip :color="$match->confidence()->color()" dot>
                                    {{ $match->confidence()->shortLabel() }}
                                </x-status-chip>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($loser === null)
            <p class="text-sm text-muted-foreground">Pick a record above to compare the two side by side.</p>
        @else
            <section class="mb-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">Which one should be kept?</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    The other is merged into it and taken off the list.
                </p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'this' => $record,
                        'other' => $other,
                    ] as $side => $option)
                        <button
                            type="button"
                            wire:click="$set('keep', '{{ $side }}')"
                            @class([
                                'rounded-lg border px-4 py-3 text-left transition-colors',
                                'border-accent bg-accent/5' => $keep === $side,
                                'border-border hover:bg-muted' => $keep !== $side,
                            ])
                            aria-pressed="{{ $keep === $side ? 'true' : 'false' }}"
                        >
                            <span class="flex items-center gap-2">
                                @if ($keep === $side)
                                    <x-icon name="lucide-circle-check" class="h-4 w-4 text-accent" />
                                @else
                                    <x-icon name="lucide-circle" class="h-4 w-4 text-muted-foreground" />
                                @endif
                                <span class="font-medium text-foreground">{{ $source->label($option) }}</span>
                            </span>
                            <span class="mt-1 block text-xs text-muted-foreground">
                                Captured {{ $option->created_at?->format('j M Y') }}
                            </span>
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="mb-6 rounded-xl border border-border bg-card p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-foreground">What should the kept record say?</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            @if ($conflicts === [])
                                The two records agree on every field, so there is nothing to choose.
                            @else
                                {{ count($conflicts) }} {{ Str::plural('field', count($conflicts)) }} differ.
                                Blanks are filled from the other record already.
                            @endif
                        </p>
                    </div>

                    @if ($conflicts !== [])
                        <div class="flex flex-wrap gap-2">
                            <x-button type="button" variant="secondary" wire:click="takeAllFrom('{{ $keep }}')">
                                Keep all from {{ $source->label($survivor) }}
                            </x-button>
                            <x-button type="button" variant="secondary" wire:click="takeAllFrom('{{ $keep === 'this' ? 'other' : 'this' }}')">
                                Take all from {{ $source->label($loser) }}
                            </x-button>
                        </div>
                    @endif
                </div>

                @if ($conflicts !== [])
                    <div class="mt-5 overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <th class="w-40 py-2 pr-3 font-semibold">Field</th>
                                    <th class="py-2 pr-3 font-semibold">{{ $source->label($record) }}</th>
                                    <th class="py-2 font-semibold">{{ $source->label($other) }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($conflicts as $field)
                                    <tr class="border-b border-border/60 last:border-0" wire:key="field-{{ $field }}">
                                        <th scope="row" class="py-2.5 pr-3 text-left font-medium text-muted-foreground">
                                            {{ $this->fields()[$field] }}
                                        </th>

                                        @foreach (['this' => $record, 'other' => $other] as $side => $option)
                                            <td class="py-1.5 pr-3">
                                                <button
                                                    type="button"
                                                    wire:click="chooseField('{{ $field }}', '{{ $side }}')"
                                                    @class([
                                                        'flex w-full items-start gap-2 rounded-lg border px-3 py-2 text-left transition-colors',
                                                        'border-accent bg-accent/5 text-foreground' => ($chosen[$field] ?? null) === $side,
                                                        'border-transparent text-muted-foreground hover:bg-muted' => ($chosen[$field] ?? null) !== $side,
                                                    ])
                                                    aria-pressed="{{ ($chosen[$field] ?? null) === $side ? 'true' : 'false' }}"
                                                >
                                                    @if (($chosen[$field] ?? null) === $side)
                                                        <x-icon name="lucide-circle-check" class="mt-0.5 h-4 w-4 shrink-0 text-accent" />
                                                    @else
                                                        <x-icon name="lucide-circle" class="mt-0.5 h-4 w-4 shrink-0" />
                                                    @endif
                                                    <span class="break-words">{{ $this->valueFor($option, $field) }}</span>
                                                </button>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <x-button
                    type="button"
                    wire:click="merge"
                    wire:confirm="Merge {{ $source->label($loser) }} into {{ $source->label($survivor) }}?"
                    wire:loading.attr="disabled"
                >
                    <x-icon name="lucide-merge" />
                    Merge into {{ $source->label($survivor) }}
                </x-button>

                <a href="{{ $source->showRoute($record) }}" wire:navigate class="text-sm font-medium text-muted-foreground hover:text-foreground">
                    Cancel
                </a>
            </div>
        @endif
    @endif
</div>
