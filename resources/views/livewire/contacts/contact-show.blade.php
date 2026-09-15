<div>
    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div
        x-data="{ message: '' }"
        x-on:contact-updated.window="message = $event.detail.message; setTimeout(() => message = '', 3000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    <x-duplicate-banner
        :matches="$duplicates"
        :merge-url="$this->mergeRoute($contact)"
        :can-merge="$this->canMergeDuplicates($contact)"
        :merged-into="$contact->mergedInto ? $this->duplicateSource()?->showRoute($contact->mergedInto) : null"
        :merged-label="$contact->mergedInto ? $this->duplicateSource()?->label($contact->mergedInto) : null"
    />

    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('contacts.index') }}" wire:navigate class="hover:text-foreground">Contacts</a>

        @if ($contact->account)
            <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
            <a href="{{ route('accounts.show', $contact->account) }}" wire:navigate class="hover:text-foreground">
                {{ $contact->account->name }}
            </a>
        @endif

        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $contact->fullName() }}</span>
    </nav>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                {{ $contact->initials() }}
            </span>

            <div>
                <h1 class="text-2xl font-semibold text-foreground">{{ $contact->fullName() }}</h1>

                @if ($contact->roleLine())
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ $contact->roleLine() }}</p>
                @endif

                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    @if ($contact->is_primary)
                        <x-status-chip color="emerald" dot>Primary contact</x-status-chip>
                    @endif

                    @if ($contact->department())
                        <x-status-chip :color="$contact->department()->color()">{{ $contact->department()->label() }}</x-status-chip>
                    @endif

                    @if ($contact->trashed())
                        <x-status-chip color="rose">Removed</x-status-chip>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('update', $contact)
                @if (! $contact->is_primary && $contact->account_id !== null)
                    <button
                        type="button"
                        wire:click="makePrimary"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
                    >
                        <x-icon name="lucide-star" />
                        Make primary
                    </button>
                @endif

                <a href="{{ route('contacts.edit', $contact) }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                    <x-icon name="lucide-pencil" />
                    Edit
                </a>
            @endcan

            @if ($contact->phone || $contact->mobile)
                @can('start', App\Domain\Social\Models\SocialConversation::class)
                    {{-- Opens or finds the thread and hands over to the inbox,
                         where Meta's window rules already live. --}}
                    <form method="POST" action="{{ route('social.start.contact', $contact) }}">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted">
                            <x-icon name="lucide-message-circle" />
                            Message on WhatsApp
                        </button>
                    </form>
                @endcan
            @endif

            @can('delete', $contact)
                <button
                    type="button"
                    wire:click="delete"
                    wire:confirm="Remove {{ $contact->fullName() }}?"
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
            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-base font-semibold text-foreground">Details</h2>

                <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Email</dt>
                        <dd class="mt-0.5 text-sm">
                            @if ($contact->email)
                                <a href="mailto:{{ $contact->email }}" class="text-accent hover:underline">{{ $contact->email }}</a>
                            @else
                                <span class="text-foreground">—</span>
                            @endif
                        </dd>
                    </div>

                    @foreach ([
                        'Phone' => $contact->phone,
                        'Mobile' => $contact->mobile,
                        'Job title' => $contact->job_title,
                        'City' => $contact->city,
                        'State or region' => $contact->state,
                        'Postal code' => $contact->postal_code,
                        'Country' => $contact->country,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach

                    @if ($contact->address_line_1)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Address</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-sm text-foreground">{{ collect([
                                $contact->address_line_1,
                                $contact->address_line_2,
                            ])->filter()->join("\n") }}</dd>
                        </div>
                    @endif

                    @if ($contact->description)
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-muted-foreground">Notes</dt>
                            <dd class="mt-0.5 text-sm text-foreground">{{ $contact->description }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-foreground">Others at this account</h2>
                    <span class="text-xs text-muted-foreground">{{ $colleagues->count() }}</span>
                </div>

                @if ($contact->account_id === null)
                    <p class="mt-3 text-sm text-muted-foreground">
                        This contact is not linked to an account yet.
                    </p>
                @elseif ($colleagues->isEmpty())
                    <p class="mt-3 text-sm text-muted-foreground">
                        The only contact at {{ $contact->account?->name }} so far.
                    </p>
                @else
                    <ul class="mt-3 divide-y divide-border">
                        @foreach ($colleagues as $colleague)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                <a href="{{ route('contacts.show', $colleague) }}" wire:navigate class="text-sm font-medium text-foreground hover:text-accent hover:underline">
                                    {{ $colleague->fullName() }}
                                </a>

                                <span class="flex items-center gap-2 text-xs text-muted-foreground">
                                    @if ($colleague->is_primary)
                                        <x-status-chip color="emerald">Primary</x-status-chip>
                                    @endif
                                    {{ $colleague->job_title }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
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
            @unless ($contact->trashed())
                <livewire:activities.book-meeting
                    :module="'contacts'"
                    :record="$contact->id"
                    :key="'book-contacts-'.$contact->id"
                />
            @endunless

            <livewire:timeline.record-timeline
                :module="'contacts'"
                :record="$contact->id"
                :key="'timeline-contacts-'.$contact->id"
            />
        </div>

        <aside class="space-y-6">
            <x-marketing-attribution :record="$contact" />

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Ownership</h2>

                <dl class="mt-3 space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Account</dt>
                        <dd class="mt-1 text-sm">
                            @if ($contact->account)
                                <a href="{{ route('accounts.show', $contact->account) }}" wire:navigate class="text-accent hover:underline">
                                    {{ $contact->account->name }}
                                </a>
                            @else
                                <span class="text-foreground">Not linked</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Owner</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm text-foreground">
                            @if ($contact->owner)
                                <x-avatar :user="$contact->owner" size="sm" />
                                {{ $contact->owner->name }}
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Created</dt>
                        <dd class="mt-1 text-sm text-foreground">{{ $contact->created_at?->format('j M Y') }}</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>
</div>
