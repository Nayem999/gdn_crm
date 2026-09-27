@props([
    // The Livewire component using EditsLeadPeople.
    'form',
    'accountIntro' => 'Optional. Link an account on file, or fill in a new account to create it when you save.',
    'newAccountHint' => 'Leave it blank to create no account now.',
    'contactsIntro' => 'Optional. Link people on file, or fill in new ones to create them when you save. New people join the account above.',
    // Records to offer first in the pickers, before anything is typed.
    'suggestedAccounts' => [],
    'suggestedContacts' => [],
])

@php
    $options = $form->peopleOptions($suggestedAccounts, $suggestedContacts);
    $canCreateAccount = $form->canCreateAccount();
    $canCreateContact = $form->canCreateContact();
@endphp

{{-- The organisation and contact-person sections of the lead form and the
     convert page. Every field is their own: nothing is copied from the lead. --}}
<section class="rounded-xl border border-border bg-card p-5 sm:p-6">
    <h2 class="text-base font-semibold text-foreground">The organisation</h2>
    <p class="mt-1 text-sm text-muted-foreground">{{ $accountIntro }}</p>

    <div class="mt-4 max-w-md" wire:key="lead-account-{{ $form->account_id }}">
        {{-- Keyed on the value: picking a contact can choose the account
             from the server, which a wire:ignore'd dropdown only shows
             once it is rebuilt. --}}
        <x-select
            name="account_id"
            label="Account"
            :options="$options['accounts']"
            :selected="$form->account_id"
            placeholder="Not on file — search accounts…"
            search-method="searchAccounts"
            preload="focus"
            clearable
            :error="$errors->first('account_id')"
            wire:model.live="account_id"
        />
    </div>

    @if (! $form->account_id && $canCreateAccount)
        <h3 class="mt-6 text-sm font-medium text-foreground">New account</h3>

        <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-form.label for="new_account_name">Account name</x-form.label>
                <x-form.input id="new_account_name" wire:model="new_account_name" :invalid="$errors->has('new_account_name')" />
                <x-form.error for="new_account_name" />
            </div>

            <div>
                <x-form.label for="new_account_email">Email</x-form.label>
                <x-form.input id="new_account_email" type="email" wire:model="new_account_email" :invalid="$errors->has('new_account_email')" />
                <x-form.error for="new_account_email" />
            </div>

            <div>
                <x-form.label for="new_account_phone">Phone</x-form.label>
                <x-form.input id="new_account_phone" wire:model="new_account_phone" :invalid="$errors->has('new_account_phone')" />
                <x-form.error for="new_account_phone" />
            </div>

            <div>
                <x-form.label for="new_account_website">Website</x-form.label>
                <x-form.input id="new_account_website" wire:model="new_account_website" placeholder="example.com" :invalid="$errors->has('new_account_website')" />
                <x-form.error for="new_account_website" />
            </div>
        </div>

        <p class="mt-1.5 text-xs text-muted-foreground">{{ $newAccountHint }}</p>
    @endif

    {{ $accountExtra ?? '' }}
</section>

<section class="rounded-xl border border-border bg-card p-5 sm:p-6">
    <h2 class="text-base font-semibold text-foreground">Contact persons</h2>
    <p class="mt-1 text-sm text-muted-foreground">{{ $contactsIntro }}</p>

    <div class="mt-4 space-y-3">
        @foreach ($form->contacts as $index => $row)
            <div class="rounded-lg border border-border p-4" wire:key="lead-contact-row-{{ $index }}">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-56 flex-1" wire:key="lead-contact-{{ $index }}-{{ $row['contact_id'] }}">
                        <x-select
                            :name="'contacts-'.$index.'-contact_id'"
                            label="Contact"
                            :options="$options['contacts'][$index] ?? []"
                            :selected="$row['contact_id']"
                            placeholder="Not on file — search contacts…"
                            search-method="searchContacts"
                            preload="focus"
                            clearable
                            :error="$errors->first('contacts.'.$index.'.contact_id')"
                            wire:model.live="contacts.{{ $index }}.contact_id"
                        />
                    </div>

                    <button
                        type="button"
                        wire:click="removeContactRow({{ $index }})"
                        class="mt-6 text-sm text-destructive underline underline-offset-4"
                    >
                        Remove
                    </button>
                </div>

                @if (! $row['contact_id'] && $canCreateContact)
                    <h3 class="mt-4 text-sm font-medium text-foreground">New person</h3>

                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @foreach ([
                            'first_name' => ['First name', 'text', false],
                            'last_name' => ['Last name', 'text', false],
                            'job_title' => ['Job title', 'text', false],
                            'email' => ['Email', 'email', false],
                            'phone' => ['Phone', 'text', false],
                            'mobile' => ['Mobile', 'text', false],
                            'address_line_1' => ['Address', 'text', true],
                            'address_line_2' => ['Address line 2', 'text', true],
                            'city' => ['City', 'text', false],
                            'state' => ['State or region', 'text', false],
                            'postal_code' => ['Postal code', 'text', false],
                            'country' => ['Country', 'text', false],
                        ] as $field => [$label, $type, $wide])
                            <div @class(['sm:col-span-2' => $wide])>
                                <x-form.label :for="'contacts-'.$index.'-'.$field">{{ $label }}</x-form.label>
                                <x-form.input
                                    :id="'contacts-'.$index.'-'.$field"
                                    :type="$type"
                                    wire:model="contacts.{{ $index }}.{{ $field }}"
                                    :invalid="$errors->has('contacts.'.$index.'.'.$field)"
                                />
                                <x-form.error :for="'contacts.'.$index.'.'.$field" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        {{ $contactsExtra ?? '' }}

        <x-button type="button" variant="secondary" wire:click="addContactRow">
            <x-icon name="lucide-user-plus" />
            Add another person
        </x-button>
    </div>
</section>
