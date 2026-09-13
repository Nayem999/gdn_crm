<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('tickets.index') }}" wire:navigate class="hover:text-foreground">Support</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="font-mono text-foreground">{{ $ticket->reference() }}</span>
    </nav>

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
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

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($ticket->trashed())
        {{-- The page still opens: a customer reading a reference down the
             telephone should not get a 404 because somebody removed it. --}}
        <x-alert variant="error" class="mb-4">
            This ticket was removed. It is kept so what was said on it survives, but nothing further can be done with it.
        </x-alert>
    @endif

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300">
                <x-icon name="lucide-life-buoy" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">{{ $ticket->subject }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    {!! \App\Domain\Shared\UI\ChipPalette::chip($ticket->status()->label(), $ticket->status()->color()) !!}
                    {!! \App\Domain\Shared\UI\ChipPalette::chip($ticket->priority()->label(), $ticket->priority()->color()) !!}
                    <span>raised {{ \App\Domain\Settings\DisplayTime::display($ticket->created_at)->diffForHumans() }}</span>
                    @if ($ticket->isOpen())
                        <span>&middot; open {{ $ticket->ageInHours() < 24 ? round($ticket->ageInHours(), 1) . ' hours' : round($ticket->ageInHours() / 24) . ' days' }}</span>
                    @elseif ($ticket->hoursToResolve() !== null)
                        <span>&middot; resolved in {{ $ticket->hoursToResolve() }} hours</span>
                    @endif
                </p>
            </div>
        </div>

        @unless ($ticket->trashed())
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->canUpdate())
                    <a href="{{ route('tickets.edit', $ticket) }}" wire:navigate
                       class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                        <x-icon name="lucide-pencil" class="h-4 w-4" />
                        Edit
                    </a>
                @endif

                @can('delete', $ticket)
                    <button
                        type="button"
                        wire:click="delete"
                        wire:confirm="Remove this ticket? What was said on it is kept."
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-destructive transition-colors hover:bg-destructive/10"
                    >
                        <x-icon name="lucide-trash-2" class="h-4 w-4" />
                        Remove
                    </button>
                @endcan
            </div>
        @endunless
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">What they told us</h2>
                @if ($ticket->description)
                    <p class="mt-3 whitespace-pre-line text-sm text-foreground">{{ $ticket->description }}</p>
                @else
                    <p class="mt-3 text-sm text-muted-foreground">Nothing was written down when this was raised.</p>
                @endif
            </section>

            {{-- Notes, documents and history, the same three strands every
                 module gets. 9.2 adds the customer-facing conversation. --}}
            <livewire:timeline.record-timeline module="tickets" :record="$ticket->id" :key="'timeline-' . $ticket->id" />
        </div>

        <div class="space-y-6">
            @unless ($ticket->trashed())
                @if ($this->canUpdate())
                    <section class="rounded-xl border border-border bg-card p-5">
                        <h2 class="text-sm font-semibold text-foreground">Move it on</h2>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($this->statusOptions() as $value => $label)
                                @continue($value === $ticket->status()->value)
                                <button
                                    type="button"
                                    wire:click="moveTo('{{ $value }}')"
                                    class="rounded-full border border-border px-3 py-1 text-xs font-medium text-muted-foreground transition-colors hover:border-accent hover:text-accent"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($this->canAssign())
                    <section class="rounded-xl border border-border bg-card p-5">
                        <h2 class="text-sm font-semibold text-foreground">Agent</h2>
                        <div class="mt-3" wire:key="assign-{{ $ticket->owner_id }}">
                            <x-select
                                name="assign"
                                :options="$this->agentOptions()"
                                :selected="$ticket->owner_id"
                                placeholder="Choose an agent…"
                                wire:change="assignTo($event.target.value)"
                            />
                        </div>
                    </section>
                @endif
            @endunless

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Details</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Reference</dt>
                        <dd class="font-mono text-xs text-foreground">{{ $ticket->reference() }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Agent</dt>
                        <dd class="text-foreground">{{ $ticket->owner->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Contact</dt>
                        <dd class="text-foreground">
                            @if ($ticket->contact)
                                <a href="{{ route('contacts.show', $ticket->contact) }}" wire:navigate class="hover:text-accent hover:underline">
                                    {{ $ticket->contact->fullName() }}
                                </a>
                            @else
                                &mdash;
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Account</dt>
                        <dd class="text-foreground">
                            @if ($ticket->account)
                                <a href="{{ route('accounts.show', $ticket->account) }}" wire:navigate class="hover:text-accent hover:underline">
                                    {{ $ticket->account->name }}
                                </a>
                            @else
                                &mdash;
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted-foreground">Came in by</dt>
                        <dd class="text-foreground">{{ $ticket->source()->label() }}</dd>
                    </div>
                    @if ($ticket->resolved_at)
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Resolved</dt>
                            <dd class="text-foreground">{{ \App\Domain\Settings\DisplayTime::display($ticket->resolved_at)->format('j M Y, H:i') }}</dd>
                        </div>
                    @endif
                    @if ($ticket->closed_at)
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Closed</dt>
                            <dd class="text-foreground">{{ \App\Domain\Settings\DisplayTime::display($ticket->closed_at)->format('j M Y, H:i') }}</dd>
                        </div>
                    @endif
                </dl>
            </section>
        </div>
    </div>
</div>
