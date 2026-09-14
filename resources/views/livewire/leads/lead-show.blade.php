<div>
    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:lead-updated.window="tone = 'success'; message = $event.detail.message; setTimeout(() => message = '', 3000)"
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

    <x-duplicate-banner
        :matches="$duplicates"
        :merge-url="$this->mergeRoute($lead)"
        :can-merge="$this->canMergeDuplicates($lead)"
        :merged-into="$lead->mergedInto ? $this->duplicateSource()?->showRoute($lead->mergedInto) : null"
        :merged-label="$lead->mergedInto ? $this->duplicateSource()?->label($lead->mergedInto) : null"
    />

    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('leads.index') }}" wire:navigate class="hover:text-foreground">Leads</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $lead->fullName() }}</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-sm font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                {{ $lead->initials() }}
            </span>

            <div>
                <h1 class="text-2xl font-semibold text-foreground">{{ $lead->fullName() }}</h1>

                @if ($lead->roleLine())
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ $lead->roleLine() }}</p>
                @endif

                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <x-status-chip :color="$lead->status()->color()" dot>{{ $lead->status()->label() }}</x-status-chip>

                    @if ($lead->source())
                        <x-status-chip :color="$lead->source()->color()">{{ $lead->source()->label() }}</x-status-chip>
                    @endif

                    <x-status-chip :color="$lead->grade()->color()" dot>
                        Score {{ $lead->score }} &middot; {{ $lead->grade()->label() }}
                    </x-status-chip>

                    <span class="text-xs text-muted-foreground">
                        {{ $lead->daysInStatus() }} {{ Str::plural('day', $lead->daysInStatus()) }} in this status
                    </span>

                    @if ($lead->trashed())
                        <x-status-chip color="rose">Removed</x-status-chip>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (! $lead->isConverted())
                @can('convert', $lead)
                    <a href="{{ route('leads.convert', $lead) }}" wire:navigate
                       class="inline-flex items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-accent-foreground transition-colors hover:bg-accent/90">
                        <x-icon name="lucide-circle-check-big" />
                        Convert
                    </a>
                @endcan
            @endif

            @can('update', $lead)
                <a href="{{ route('leads.edit', $lead) }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    <x-icon name="lucide-pencil" />
                    Edit
                </a>
            @endcan

            @can('delete', $lead)
                <button
                    type="button"
                    wire:click="delete"
                    wire:confirm="Remove {{ $lead->fullName() }}?"
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
            @if ($lead->isConverted())
                <section class="rounded-xl border border-emerald-300 bg-emerald-50 p-5 dark:border-emerald-500/30 dark:bg-emerald-500/10 sm:p-6">
                    <h2 class="flex items-center gap-2 text-base font-semibold text-emerald-900 dark:text-emerald-200">
                        <x-icon name="lucide-circle-check-big" class="h-4 w-4" />
                        Converted{{ $lead->converted_at ? ' on '.$lead->converted_at->format('j M Y') : '' }}
                    </h2>

                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                        @foreach ([
                            'Account' => $lead->convertedAccount ? ['accounts.show', $lead->convertedAccount, $lead->convertedAccount->name] : null,
                            'Contact' => $lead->convertedContact ? ['contacts.show', $lead->convertedContact, $lead->convertedContact->fullName()] : null,
                            'Deal' => $lead->convertedDeal ? [null, null, $lead->convertedDeal->name] : null,
                        ] as $label => $target)
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-emerald-800/70 dark:text-emerald-200/60">{{ $label }}</dt>
                                <dd class="mt-0.5 text-sm">
                                    @if ($target === null)
                                        <span class="text-emerald-900/60 dark:text-emerald-200/50">&mdash;</span>
                                    @elseif ($target[0] === null)
                                        {{-- Deals have no screen until Phase 3.2. --}}
                                        <span class="font-medium text-emerald-900 dark:text-emerald-100">{{ $target[2] }}</span>
                                    @else
                                        <a href="{{ route($target[0], $target[1]) }}" wire:navigate
                                           class="font-medium text-emerald-900 underline hover:no-underline dark:text-emerald-100">
                                            {{ $target[2] }}
                                        </a>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @elsecan('update', $lead)
                <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                    <h2 class="text-base font-semibold text-foreground">Move this lead on</h2>

                    @if ($transitions === [])
                        <p class="mt-2 text-sm text-muted-foreground">
                            @if ($lead->isConverted())
                                This lead has been converted, so it stays where it is.
                            @else
                                There is nowhere to move this lead from here.
                            @endif
                        </p>
                    @else
                        <p class="mt-1 text-sm text-muted-foreground">
                            From <strong>{{ $lead->status()->label() }}</strong>, these are the moves available.
                        </p>

                        @if ($qualification->requirements !== [])
                            <div class="mt-3 rounded-lg border border-border bg-muted/40 p-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Before qualifying
                                </p>

                                <ul class="mt-2 space-y-1">
                                    @foreach ($qualification->requirements as $requirement)
                                        <li class="flex items-start gap-2 text-sm">
                                            @if ($requirement['met'])
                                                <x-icon name="lucide-circle-check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                                <span class="text-foreground">{{ $requirement['label'] }}</span>
                                            @else
                                                <x-icon name="lucide-circle" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                                <span class="text-muted-foreground">{{ $requirement['label'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($transitions as $target)
                                <button
                                    type="button"
                                    wire:click="changeStatus('{{ $target->value }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                                    title="{{ $target->description() }}"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full {{ \App\Domain\Shared\UI\ChipPalette::dotClasses($target->color()) }}" aria-hidden="true"></span>
                                    {{ $target->label() }}
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
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Email</dt>
                        <dd class="mt-0.5 text-sm">
                            @if ($lead->email)
                                <a href="mailto:{{ $lead->email }}" class="text-accent hover:underline">{{ $lead->email }}</a>
                            @else
                                <span class="text-foreground">—</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Website</dt>
                        <dd class="mt-0.5 text-sm">
                            @if ($lead->websiteUrl())
                                <a href="{{ $lead->websiteUrl() }}" target="_blank" rel="noopener noreferrer" class="text-accent hover:underline">
                                    {{ $lead->website }}
                                </a>
                            @else
                                <span class="text-foreground">—</span>
                            @endif
                        </dd>
                    </div>

                    @foreach ([
                        'Phone' => $lead->phone,
                        'Mobile' => $lead->mobile,
                        'Company' => $lead->company_name,
                        'Job title' => $lead->job_title,
                        'Estimated value' => $lead->estimated_value === null
                            ? null
                            : \App\Domain\Settings\NumberFormat::format((float) $lead->estimated_value, 0),
                        'City' => $lead->city,
                        'State or region' => $lead->state,
                        'Postal code' => $lead->postal_code,
                        'Country' => $lead->country,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach

                    @if ($lead->address_line_1)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Address</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-sm text-foreground">{{ collect([
                                $lead->address_line_1,
                                $lead->address_line_2,
                            ])->filter()->join("\n") }}</dd>
                        </div>
                    @endif

                    @if ($lead->description)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Notes</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $lead->description }}</dd>
                        </div>
                    @endif
                </dl>
            </section>
            {{-- The record's own history, keyed so switching records rebuilds
                 it rather than showing the previous one's entries. --}}
            {{-- Booking sits above the timeline: it is the thing people come
                 to a record to do, and the meeting it creates appears in the
                 strand directly below it.

                 Not offered on a merged record. Its page stays readable so its
                 history survives, but the live record is the one to book with,
                 and the picker resolves through a query that — rightly — does
                 not return soft-deleted rows. --}}
            @unless ($lead->trashed())
                <livewire:activities.book-meeting
                    :module="'leads'"
                    :record="$lead->id"
                    :key="'book-leads-'.$lead->id"
                />
            @endunless

            <livewire:timeline.record-timeline
                :module="'leads'"
                :record="$lead->id"
                :key="'timeline-leads-'.$lead->id"
            />
        </div>

        <aside class="space-y-6">
            <x-marketing-attribution :record="$lead" />

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Ownership</h2>

                <dl class="mt-3 space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Owner</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm text-foreground">
                            @if ($lead->owner)
                                <x-avatar :user="$lead->owner" size="sm" />
                                {{ $lead->owner->name }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Captured</dt>
                        <dd class="mt-1 text-sm text-foreground">{{ $lead->created_at?->format('j M Y') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Status changed</dt>
                        <dd class="mt-1 text-sm text-foreground">
                            {{ $lead->status_changed_at?->format('j M Y') ?? '—' }}
                        </dd>
                    </div>
                </dl>

                @can('assign', $lead)
                    <div class="mt-4 border-t border-border pt-4">
                        <x-select
                            name="reassignTo"
                            label="Hand to"
                            :options="$owners"
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
            <section class="rounded-xl border border-border bg-card p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-foreground">Score</h2>
                    <x-status-chip :color="$lead->grade()->color()" dot>{{ $lead->grade()->label() }}</x-status-chip>
                </div>

                <p class="mt-2 text-3xl font-semibold text-foreground">{{ $lead->score }}<span class="text-base text-muted-foreground">/100</span></p>

                @if ($breakdown->matched === [])
                    <p class="mt-2 text-sm text-muted-foreground">
                        No scoring rule matched this lead.
                    </p>
                @else
                    <ul class="mt-3 space-y-1.5">
                        @foreach ($breakdown->matched as $rule)
                            <li class="flex items-start justify-between gap-3 text-sm">
                                <span class="text-muted-foreground">{{ $rule['label'] }}</span>
                                <span class="shrink-0 font-medium {{ $rule['points'] < 0 ? 'text-destructive' : 'text-foreground' }}">
                                    {{ $rule['points'] > 0 ? '+' : '' }}{{ $rule['points'] }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($breakdown->wasClamped())
                        <p class="mt-2 text-xs text-muted-foreground">
                            The rules total {{ $breakdown->points }}, held to {{ $breakdown->score }}.
                        </p>
                    @endif
                @endif

                @if ($lead->scored_at)
                    <p class="mt-3 text-xs text-muted-foreground">
                        Last scored {{ $lead->scored_at->diffForHumans() }}.
                    </p>
                @endif
            </section>
        </aside>
    </div>
</div>
