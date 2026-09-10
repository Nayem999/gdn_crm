@php
    use App\Domain\Settings\NumberFormat;
    use App\Domain\Shared\UI\ChipPalette;
@endphp

<div>
    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:deal-updated.window="tone = 'success'; message = $event.detail.message; setTimeout(() => message = '', 3000)"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 5000)"
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
        <a href="{{ route('deals.index') }}" wire:navigate class="hover:text-foreground">Deals</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $deal->name }}</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
                <x-icon name="lucide-handshake" class="h-5 w-5" />
            </span>

            <div>
                <h1 class="text-2xl font-semibold text-foreground">{{ $deal->name }}</h1>

                @if ($deal->account)
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        <a href="{{ route('accounts.show', $deal->account) }}" wire:navigate class="hover:text-foreground hover:underline">
                            {{ $deal->account->name }}
                        </a>
                    </p>
                @endif

                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    @php($stage = $deal->configuredStage())
                    <x-status-chip :color="$stage?->color ?? $deal->stage()->color()" dot>
                        {{ $stage?->name ?? $deal->stage()->label() }}
                    </x-status-chip>

                    @if ($deal->closeReason())
                        <x-status-chip :color="$deal->closeReason()->color()">
                            {{ $deal->closeReason()->label() }}
                        </x-status-chip>
                    @elseif (! $deal->isOpen())
                        <x-status-chip color="amber">No reason recorded</x-status-chip>
                    @endif

                    @if ($deal->isOverdue())
                        <x-status-chip color="rose" dot>Overdue</x-status-chip>
                    @endif

                    @if ($deal->trashed())
                        <x-status-chip color="rose">Removed</x-status-chip>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('update', $deal)
                <a href="{{ route('deals.edit', $deal) }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    <x-icon name="lucide-pencil" />
                    Edit
                </a>
            @endcan

            @can('delete', $deal)
                <button
                    type="button"
                    wire:click="delete"
                    wire:confirm="Remove {{ $deal->name }}?"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-destructive transition-colors hover:bg-destructive/10"
                >
                    <x-icon name="lucide-trash-2" />
                    Remove
                </button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- The closing form, shown only once a closing stage was picked. --}}
            @if ($closing)
                <section class="rounded-xl border border-accent bg-accent/5 p-5 sm:p-6">
                    <h2 class="text-base font-semibold text-foreground">Why is this deal ending?</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        The reason is what the win/loss report groups by, and it cannot be filled in later
                        without somebody remembering.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div wire:key="close-reason-{{ $closeStage }}">
                            <x-select
                                name="closeReason"
                                label="Reason"
                                :options="$this->closeReasonOptions()"
                                :selected="$closeReason"
                                placeholder="Choose a reason…"
                                required
                                :error="$errors->first('closeReason')"
                                wire:model="closeReason"
                            />
                        </div>

                        <div>
                            <x-form.label for="closeNotes">Anything to add</x-form.label>
                            <x-form.input id="closeNotes" wire:model="closeNotes" :invalid="$errors->has('closeNotes')" placeholder="Optional detail" />
                            <x-form.error for="closeNotes" />
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <x-button type="button" wire:click="close" wire:loading.attr="disabled" wire:target="close">
                            <x-icon name="lucide-circle-check-big" />
                            Close this deal
                        </x-button>

                        <x-button type="button" variant="secondary" wire:click="cancelClosing">Cancel</x-button>
                    </div>
                </section>
            @elsecan('update', $deal)
                <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                    <h2 class="text-base font-semibold text-foreground">Move this deal on</h2>

                    @if ($stages->isEmpty())
                        <p class="mt-2 text-sm text-muted-foreground">
                            @if ($deal->pipeline === null)
                                This deal is not on a pipeline, so there is nowhere to move it. Edit it to choose one.
                            @else
                                The {{ $deal->pipeline->name }} pipeline has no other stage to move to.
                            @endif
                        </p>
                    @else
                        <p class="mt-1 text-sm text-muted-foreground">
                            On <strong>{{ $deal->pipeline?->name }}</strong>, from
                            <strong>{{ $stage?->name ?? $deal->stage()->label() }}</strong>.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($stages as $target)
                                @continue($target->isClosed() && ! auth()->user()->can('close', $deal))
                                <button
                                    type="button"
                                    wire:click="moveTo('{{ $target->key }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                                    title="{{ $target->probability }}% likely to close"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full {{ ChipPalette::dotClasses($target->color) }}" aria-hidden="true"></span>
                                    {{ $target->name }}
                                    @if ($target->isClosed())
                                        <span class="text-xs text-muted-foreground">— closes it</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endcan

            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">Details</h2>

                <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Value</dt>
                        <dd class="mt-0.5 text-sm text-foreground">
                            {{ $deal->value === null ? '—' : NumberFormat::format((float) $deal->value, 0) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Weighted value</dt>
                        <dd class="mt-0.5 text-sm text-foreground">
                            @if ($deal->value === null)
                                —
                            @else
                                {{ NumberFormat::format($deal->weightedValue(), 0) }}
                                <span class="text-xs text-muted-foreground">
                                    at {{ $stage?->probability ?? $deal->stage()->probability() }}%
                                </span>
                            @endif
                        </dd>
                    </div>

                    @foreach ([
                        'Expected close' => $deal->expected_close_date?->format('j M Y'),
                        'Closed' => $deal->closed_at?->format('j M Y'),
                        'Contact' => $deal->contact?->fullName(),
                        'Pipeline' => $deal->pipeline?->name,
                        'Created' => $deal->created_at?->format('j M Y'),
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach

                    @if ($deal->daysToClose() !== null)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Days to close</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $deal->daysToClose() }}</dd>
                        </div>
                    @endif

                    @if ($deal->close_notes)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Closing notes</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-sm text-foreground">{{ $deal->close_notes }}</dd>
                        </div>
                    @endif

                    @if ($deal->description)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Notes</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-sm text-foreground">{{ $deal->description }}</dd>
                        </div>
                    @endif

                    @if ($deal->lead)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Converted from</dt>
                            <dd class="mt-0.5 text-sm">
                                <a href="{{ route('leads.show', $deal->lead) }}" wire:navigate class="text-accent hover:underline">
                                    {{ $deal->lead->fullName() }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- Where the deal has been, and for how long. Separate from the
                 timeline below, which merges notes, documents and the audit
                 trail: this answers "how long did Negotiation take", which is a
                 duration rather than an event. --}}
            <section class="rounded-xl border border-border bg-card p-5 sm:p-6" aria-labelledby="stage-history-heading">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="stage-history-heading" class="text-base font-semibold text-foreground">Stage history</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Every stage this deal has sat in, and how long it stayed.
                        </p>
                    </div>

                    @php($cycleDays = intdiv($deal->cycleSeconds(), 86400))
                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">
                            {{ $deal->isOpen() ? 'Open for' : 'Took' }}
                        </p>
                        <p class="text-sm font-semibold tabular-nums text-foreground">
                            {{ $cycleDays < 1 ? 'under a day' : $cycleDays.' '.Str::plural('day', $cycleDays) }}
                        </p>
                    </div>
                </div>

                @if ($history->isEmpty())
                    <p class="mt-4 rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                        Nothing recorded yet.
                    </p>
                @else
                    <ol role="list" class="mt-4">
                        @foreach ($history as $entry)
                            <li class="relative flex gap-3 pb-5 last:pb-0" wire:key="stage-entry-{{ $entry->id }}">
                                @unless ($loop->last)
                                    <span class="absolute left-3 top-7 -ml-px h-[calc(100%-1.25rem)] w-px bg-border" aria-hidden="true"></span>
                                @endunless

                                <span class="relative z-10 mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full ring-4 ring-card {{ ChipPalette::classes($entry->outcome()->color()) }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ ChipPalette::dotClasses($entry->outcome()->color()) }}" aria-hidden="true"></span>
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <p class="text-sm font-medium text-foreground">
                                            {{ $entry->stage_name }}
                                            @if ($entry->isOpen())
                                                <span class="ml-1 text-xs font-normal text-muted-foreground">&mdash; still here</span>
                                            @endif
                                        </p>

                                        <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ $entry->forHumans() }}</span>
                                    </div>

                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        <time datetime="{{ $entry->entered_at->toIso8601String() }}" title="{{ $entry->entered_at->format('j M Y, H:i') }}">
                                            {{ $entry->entered_at->format('j M Y') }}
                                        </time>

                                        @if ($entry->movedBy)
                                            &middot; moved by {{ $entry->movedBy->name }}
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @php($perStage = $this->timePerStage())
                    @if (count($perStage) < $history->count())
                        {{-- Only worth showing once some stage has been visited
                             more than once; otherwise it repeats the list. --}}
                        <div class="mt-4 border-t border-border pt-4">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">Total per stage</p>

                            <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                @foreach ($perStage as $stageTotal)
                                    @php($stageDays = intdiv($stageTotal['seconds'], 86400))
                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                        <dt class="text-muted-foreground">
                                            {{ $stageTotal['name'] }}
                                            @if ($stageTotal['visits'] > 1)
                                                <span class="text-xs">&times;{{ $stageTotal['visits'] }}</span>
                                            @endif
                                        </dt>
                                        <dd class="shrink-0 tabular-nums text-foreground">
                                            {{ $stageDays < 1 ? 'under a day' : $stageDays.'d' }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                @endif
            </section>

            {{-- The record's own history, keyed so switching records rebuilds
                 it rather than showing the previous one's entries. --}}
            <livewire:timeline.record-timeline
                :module="'deals'"
                :record="$deal->id"
                :key="'timeline-deals-'.$deal->id"
            />
        </div>

        <aside class="space-y-6">
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Ownership</h2>

                <dl class="mt-3 space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Owner</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm text-foreground">
                            @if ($deal->owner)
                                <x-avatar :user="$deal->owner" size="sm" />
                                {{ $deal->owner->name }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>

                @can('assign', $deal)
                    <div class="mt-4 border-t border-border pt-4">
                        <x-select
                            name="reassignTo"
                            label="Hand to"
                            :options="$this->ownerOptions()"
                            :selected="$reassignTo"
                            placeholder="Choose somebody…"
                            :error="$errors->first('reassignTo')"
                            wire:model="reassignTo"
                        />

                        <x-button type="button" variant="secondary" class="mt-2 w-full" wire:click="reassign" wire:loading.attr="disabled">
                            <x-icon name="lucide-user-round-check" />
                            Reassign
                        </x-button>
                    </div>
                @endcan
            </section>

            @unless ($deal->isOpen())
                @can('close', $deal)
                    <section class="rounded-xl border border-border bg-card p-5">
                        <h2 class="text-sm font-semibold text-foreground">Put it back in play</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Reopening clears the recorded reason — a live deal that still says why it was lost
                            reads as a closed one.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($stages as $target)
                                @continue($target->isClosed())
                                <button
                                    type="button"
                                    wire:click="reopen('{{ $target->key }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full {{ ChipPalette::dotClasses($target->color) }}" aria-hidden="true"></span>
                                    {{ $target->name }}
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endcan
            @endunless
        </aside>
    </div>
</div>
