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
            @can('update', $lead)
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
        </div>

        <aside class="space-y-6">
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
        </aside>
    </div>
</div>
