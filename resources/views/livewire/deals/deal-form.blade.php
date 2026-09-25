<div>
    <nav class="mb-3 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground" aria-label="Breadcrumb">
        <a href="{{ route('deals.index') }}" wire:navigate class="hover:text-foreground">Deals</a>
        <x-icon name="lucide-chevron-right" class="h-3.5 w-3.5" />
        <span class="text-foreground">{{ $this->isEditing() ? 'Edit' : 'Add deal' }}</span>
    </nav>

    <h1 class="mb-6 text-2xl font-semibold text-foreground">
        {{ $this->isEditing() ? 'Edit deal' : 'Add deal' }}
    </h1>

    <form wire:submit="save" class="max-w-3xl space-y-6">
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">The deal</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="name" required>Name</x-form.label>
                    <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" placeholder="Acme — 40 seat rollout" />
                    <x-form.error for="name" />
                </div>

                <div>
                    <x-form.label for="value">Value</x-form.label>
                    <x-form.input
                        id="value"
                        type="number"
                        step="0.01"
                        min="0"
                        wire:model="value"
                        :invalid="$errors->has('value')"
                        placeholder="0.00"
                    />
                    <x-form.error for="value" />
                </div>

                <div>
                    <x-form.label for="expected_close_date">Expected close date</x-form.label>
                    <x-form.input
                        id="expected_close_date"
                        type="date"
                        wire:model="expected_close_date"
                        :invalid="$errors->has('expected_close_date')"
                    />
                    <x-form.error for="expected_close_date" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="description">Notes</x-form.label>
                    <textarea
                        id="description"
                        wire:model="description"
                        rows="3"
                        @class([
                            'w-full rounded-lg border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground',
                            'focus:outline-none focus:ring-2',
                            'border-destructive focus:border-destructive focus:ring-destructive/40' => $errors->has('description'),
                            'border-border focus:border-accent focus:ring-accent/40' => ! $errors->has('description'),
                        ])
                        placeholder="What is this deal about?"
                    ></textarea>
                    <x-form.error for="description" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Who it is with</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Server-side search: there can be far more accounts than a
                     dropdown should hold, so it pages as the user scrolls. --}}
                <x-select
                    name="account_id"
                    label="Account"
                    :options="[]"
                    :selected="$account_id"
                    placeholder="Search accounts…"
                    search-method="searchAccounts"
                    preload
                    required
                    :error="$errors->first('account_id')"
                    hint="A deal is always for an organisation."
                    wire:model.live="account_id"
                />

                {{-- Depends on the account: the kit clears and reloads it when
                     the parent changes, so a contact from the previous account
                     cannot be left selected. Keyed on the account so the field
                     behind wire:ignore is rebuilt when the parent moves. --}}
                <div wire:key="contact-picker-{{ $account_id ?? 'none' }}">
                    <x-select
                        name="contact_id"
                        label="Contact"
                        :options="[]"
                        :selected="$contact_id"
                        placeholder="Search this account's people…"
                        search-method="searchContacts"
                        depends-on="account_id"
                        clearable
                        :error="$errors->first('contact_id')"
                        hint="Who to talk to there."
                        wire:model="contact_id"
                    />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">How it is worked</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-select
                    name="pipeline_id"
                    label="Pipeline"
                    :options="$this->pipelineOptions()"
                    :selected="$pipeline_id"
                    placeholder="Choose a pipeline…"
                    :error="$errors->first('pipeline_id')"
                    :hint="$this->isEditing()
                        ? 'Only a pipeline that has the stage this deal sits in.'
                        : 'The deal starts at this pipeline\'s first open stage.'"
                    wire:model="pipeline_id"
                />

                @if ($this->canAssign())
                    <x-select
                        name="owner_id"
                        label="Owner"
                        :options="$this->ownerOptions()"
                        :selected="$owner_id"
                        placeholder="Choose an owner…"
                        required
                        :error="$errors->first('owner_id')"
                        hint="Record visibility follows the owner."
                        wire:model="owner_id"
                    />
                @endif

                <x-select
                    name="campaign_id"
                    label="Campaign"
                    :options="$this->campaignOptions()"
                    :selected="$campaign_id"
                    placeholder="Not from a campaign"
                    :error="$errors->first('campaign_id')"
                    wire:model="campaign_id"
                />
            </div>
        </section>

        {{-- The fields an administrator has added to this module. Renders
             nothing at all until there are some, so a module with none looks
             exactly as it did before custom fields existed. --}}
        <x-custom-fields :form="$this" />

        <div class="flex items-center gap-2">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <x-icon name="lucide-check" class="h-4 w-4" />
                {{ $this->isEditing() ? 'Save deal' : 'Create deal' }}
            </x-button>

            <a
                href="{{ $this->isEditing() ? route('deals.show', $dealId) : route('deals.index') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
            >
                Cancel
            </a>
        </div>
    </form>
</div>
