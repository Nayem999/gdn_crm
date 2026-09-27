@php
    $accountMatches = $this->accountMatches();
    $contactMatches = $this->contactMatches();
@endphp

<div>
    <div
        x-data="{ message: '' }"
        x-on:notify.window="message = $event.detail.message; setTimeout(() => message = '', 6000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="error"><span x-text="message"></span></x-alert>
    </div>

    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('leads.show', $lead) }}" wire:navigate class="hover:text-foreground">{{ $lead->fullName() }}</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">Convert</span>
    </nav>

    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-foreground">Convert {{ $lead->fullName() }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            The lead becomes an account, a person at it, and a deal to work. It is kept, marked
            converted, and links to all three.
        </p>
    </div>

    <form wire:submit="convert" class="space-y-6">
        <x-lead-people
            :form="$this"
            :suggested-accounts="$this->suggestedAccountIds()"
            :suggested-contacts="$this->suggestedContactIds()"
            :account-intro="$accountMatches === []
                ? 'Pick an account on file, or check the new account below — it is created from these fields.'
                : count($accountMatches).' '.Str::plural('account', count($accountMatches)).' on file already '.(count($accountMatches) === 1 ? 'looks' : 'look').' like this one. Joining an existing account is better than starting a second copy of it.'"
            new-account-hint="Filled in from the lead. Change anything before converting."
            contacts-intro="Pick people on file, or check the new people below — each is created from its own fields. The first person is the deal's contact; the others join the account too."
        >
            @if ($accountMatches !== [])
                <x-slot:account-extra>
                    <ul class="mt-3 space-y-1">
                        @foreach ($accountMatches as $match)
                            <li class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <a href="{{ route('accounts.show', $match->record) }}" wire:navigate class="font-medium text-accent hover:underline">
                                    {{ $match->record->name }}
                                </a>
                                <span>&mdash; {{ $match->summary() }}</span>
                                @if ($match->confidence())
                                    <x-status-chip :color="$match->confidence()->color()" dot>
                                        {{ $match->confidence()->shortLabel() }}
                                    </x-status-chip>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-slot:account-extra>
            @endif

            @if ($contactMatches !== [])
                <x-slot:contacts-extra>
                    <div class="rounded-lg border border-dashed border-border p-3">
                        <p class="text-sm text-muted-foreground">Somebody on file already looks like {{ $lead->fullName() }}:</p>
                        <ul class="mt-2 space-y-1">
                            @foreach ($contactMatches as $match)
                                <li class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                    <a href="{{ route('contacts.show', $match->record) }}" wire:navigate class="font-medium text-accent hover:underline">
                                        {{ $match->record->fullName() }}
                                    </a>
                                    <span>&mdash; {{ $match->summary() }}</span>
                                    @if ($match->confidence())
                                        <x-status-chip :color="$match->confidence()->color()" dot>
                                            {{ $match->confidence()->shortLabel() }}
                                        </x-status-chip>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </x-slot:contacts-extra>
            @endif
        </x-lead-people>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-foreground">The deal</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        A lead can become a customer with nothing in play yet.
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm text-foreground">
                    <input
                        type="checkbox"
                        wire:model.live="createDeal"
                        class="h-4 w-4 rounded border-border text-accent focus:ring-accent/40"
                    />
                    Open a deal
                </label>
            </div>

            @if ($createDeal)
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-3">
                        <x-form.label for="dealName">Name</x-form.label>
                        <x-form.input id="dealName" wire:model="dealName" :invalid="$errors->has('dealName')" />
                        <x-form.error for="dealName" />
                    </div>

                    <div>
                        <x-form.label for="dealValue">Value</x-form.label>
                        <x-form.input id="dealValue" type="number" step="0.01" wire:model="dealValue" :invalid="$errors->has('dealValue')" />
                        <x-form.error for="dealValue" />
                    </div>

                    <div>
                        <x-form.label for="dealCloseDate">Expected close</x-form.label>
                        <x-form.input id="dealCloseDate" type="date" wire:model="dealCloseDate" :invalid="$errors->has('dealCloseDate')" />
                        <x-form.error for="dealCloseDate" />
                    </div>
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Ownership</h2>

            <div class="mt-4 max-w-sm" wire:key="convert-owner-{{ $ownerId }}">
                <x-select
                    name="ownerId"
                    label="Owner of all three"
                    :options="$owners"
                    :selected="$ownerId"
                    :error="$errors->first('ownerId')"
                    wire:model="ownerId"
                />
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit" wire:loading.attr="disabled">
                <x-icon name="lucide-circle-check-big" />
                Convert lead
            </x-button>

            <a href="{{ route('leads.show', $lead) }}" wire:navigate class="text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </a>
        </div>
    </form>
</div>
