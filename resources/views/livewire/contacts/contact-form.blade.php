<div>
    <div class="mb-6">
        <a href="{{ route('contacts.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
            <x-icon name="lucide-arrow-left" class="h-4 w-4" />
            Contacts
        </a>
        <h1 class="mt-2 text-2xl font-semibold text-foreground">
            {{ $this->isEditing() ? 'Edit contact' : 'New contact' }}
        </h1>
    </div>

    <x-duplicate-warning
        :matches="$this->draftDuplicates()"
        :route="fn ($record) => route('contacts.show', $record)"
    />

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Person</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="first_name" required>First name</x-form.label>
                    <x-form.input id="first_name" wire:model="first_name" :invalid="$errors->has('first_name')" />
                    <x-form.error for="first_name" />
                </div>

                <div>
                    <x-form.label for="last_name" required>Last name</x-form.label>
                    <x-form.input id="last_name" wire:model="last_name" :invalid="$errors->has('last_name')" />
                    <x-form.error for="last_name" />
                </div>

                <div>
                    <x-form.label for="job_title">Job title</x-form.label>
                    <x-form.input id="job_title" wire:model="job_title" :invalid="$errors->has('job_title')" />
                    <x-form.error for="job_title" />
                </div>

                <x-select
                    name="department"
                    label="Department"
                    :options="$departments"
                    :selected="$department"
                    placeholder="Choose a department…"
                    :error="$errors->first('department')"
                    wire:model="department"
                />

                <div class="sm:col-span-2">
                    <x-form.label for="description">Notes</x-form.label>
                    <textarea
                        id="description"
                        rows="3"
                        wire:model="description"
                        @class([
                            'w-full rounded-lg border bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2',
                            'border-destructive focus:ring-destructive/40' => $errors->has('description'),
                            'border-border focus:border-accent focus:ring-accent/40' => ! $errors->has('description'),
                        ])
                    ></textarea>
                    <x-form.error for="description" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">How to reach them</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="email">Email</x-form.label>
                    <x-form.input id="email" type="email" wire:model.live.debounce.500ms="email" :invalid="$errors->has('email')" />
                    <x-form.error for="email" />
                </div>

                <div>
                    <x-form.label for="phone">Phone</x-form.label>
                    <x-form.input id="phone" wire:model.live.debounce.500ms="phone" :invalid="$errors->has('phone')" />
                    <x-form.error for="phone" />
                </div>

                <div>
                    <x-form.label for="mobile">Mobile</x-form.label>
                    <x-form.input id="mobile" wire:model.live.debounce.500ms="mobile" :invalid="$errors->has('mobile')" />
                    <x-form.error for="mobile" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="address_line_1">Address</x-form.label>
                    <x-form.input id="address_line_1" wire:model="address_line_1" :invalid="$errors->has('address_line_1')" />
                    <x-form.error for="address_line_1" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="address_line_2">Address line 2</x-form.label>
                    <x-form.input id="address_line_2" wire:model="address_line_2" :invalid="$errors->has('address_line_2')" />
                    <x-form.error for="address_line_2" />
                </div>

                <div>
                    <x-form.label for="city">City</x-form.label>
                    <x-form.input id="city" wire:model="city" :invalid="$errors->has('city')" />
                    <x-form.error for="city" />
                </div>

                <div>
                    <x-form.label for="state">State or region</x-form.label>
                    <x-form.input id="state" wire:model="state" :invalid="$errors->has('state')" />
                    <x-form.error for="state" />
                </div>

                <div>
                    <x-form.label for="postal_code">Postal code</x-form.label>
                    <x-form.input id="postal_code" wire:model="postal_code" :invalid="$errors->has('postal_code')" />
                    <x-form.error for="postal_code" />
                </div>

                <div>
                    <x-form.label for="country">Country</x-form.label>
                    <x-form.input id="country" wire:model="country" :invalid="$errors->has('country')" />
                    <x-form.error for="country" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Organisation &amp; ownership</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Server-side search: there can be far more accounts than a
                     dropdown should hold, so it pages as the user scrolls. --}}
                <x-select
                    name="account_id"
                    label="Account"
                    :options="$accounts"
                    :selected="$account_id"
                    placeholder="Search accounts…"
                    search-method="searchAccounts"
                    :error="$errors->first('account_id')"
                    hint="Leave blank if you do not know where they work yet."
                    wire:model="account_id"
                />

                <x-select
                    name="owner_id"
                    label="Owner"
                    :options="$owners"
                    :selected="$owner_id"
                    placeholder="Choose an owner…"
                    :error="$errors->first('owner_id')"
                    required
                    hint="Record visibility follows the owner."
                    wire:model="owner_id"
                />

                <x-select
                    name="campaign_id"
                    label="Campaign"
                    :options="$campaigns"
                    :selected="$campaign_id"
                    placeholder="Not from a campaign"
                    :error="$errors->first('campaign_id')"
                    wire:model="campaign_id"
                />

                <div class="sm:col-span-2">
                    <label class="flex items-start gap-3 rounded-lg border border-border px-4 py-3">
                        <input
                            type="checkbox"
                            class="mt-0.5 rounded border-border text-accent focus:ring-accent/40"
                            wire:model="is_primary"
                        />
                        <span>
                            <span class="block text-sm font-medium text-foreground">Primary contact for this account</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                An account has one primary contact. Marking this person takes it from whoever holds it.
                            </span>
                        </span>
                    </label>
                    <x-form.error for="is_primary" />
                </div>
            </div>
        </section>

        {{-- The fields an administrator has added to this module. Renders
             nothing at all until there are some, so a module with none looks
             exactly as it did before custom fields existed. --}}
        <x-custom-fields :form="$this" />

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                {{ $this->isEditing() ? 'Save changes' : 'Create contact' }}
            </x-button>

            <a
                href="{{ $this->isEditing() ? route('contacts.show', $this->contactId) : route('contacts.index') }}"
                wire:navigate
                class="text-sm font-medium text-muted-foreground hover:text-foreground"
            >
                Cancel
            </a>
        </div>
    </form>
</div>
