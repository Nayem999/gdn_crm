<div>
    <div class="mb-6">
        <a href="{{ route('leads.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
            <x-icon name="lucide-arrow-left" class="h-4 w-4" />
            Leads
        </a>
        <h1 class="mt-2 text-2xl font-semibold text-foreground">
            {{ $this->isEditing() ? 'Edit lead' : 'Capture lead' }}
        </h1>

        @unless ($this->isEditing())
            <p class="mt-1 text-sm text-muted-foreground">
                New leads start as <strong>New</strong>. Move them along from the lead's own page or the board.
            </p>
        @endunless
    </div>

    <x-duplicate-warning
        :matches="$this->draftDuplicates()"
        :route="fn ($record) => route('leads.show', $record)"
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

                <div>
                    <x-form.label for="company_name">Company</x-form.label>
                    <x-form.input id="company_name" wire:model.live.debounce.500ms="company_name" :invalid="$errors->has('company_name')" />
                    <x-form.error for="company_name" />
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        As they gave it. Converting the lead is what creates the account.
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">How to reach them</h2>
            <p class="mt-1 text-sm text-muted-foreground">An email address or a phone number — at least one.</p>

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

                <div>
                    <x-form.label for="website">Website</x-form.label>
                    <x-form.input id="website" wire:model="website" placeholder="example.com" :invalid="$errors->has('website')" />
                    <x-form.error for="website" />
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
            <h2 class="text-base font-semibold text-foreground">Qualification &amp; ownership</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-select
                    name="source"
                    label="Source"
                    :options="$sources"
                    :selected="$source"
                    placeholder="Where did they come from?"
                    :error="$errors->first('source')"
                    wire:model="source"
                />

                <div>
                    <x-form.label for="estimated_value">Estimated value</x-form.label>
                    <x-form.input id="estimated_value" type="number" step="0.01" min="0" wire:model="estimated_value" :invalid="$errors->has('estimated_value')" />
                    <x-form.error for="estimated_value" />
                    <p class="mt-1.5 text-xs text-muted-foreground">Totalled per column on the pipeline board.</p>
                </div>

                <x-select
                    name="owner_id"
                    label="Owner"
                    :options="$owners"
                    :selected="$owner_id"
                    placeholder="Choose an owner…"
                    :error="$errors->first('owner_id')"
                    required
                    hint="Who will work this lead. Record visibility follows the owner."
                    wire:model="owner_id"
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

        {{-- The fields an administrator has added to this module. Renders
             nothing at all until there are some, so a module with none looks
             exactly as it did before custom fields existed. --}}
        <x-custom-fields :form="$this" />

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                {{ $this->isEditing() ? 'Save changes' : 'Capture lead' }}
            </x-button>

            <a
                href="{{ $this->isEditing() ? route('leads.show', $this->leadId) : route('leads.index') }}"
                wire:navigate
                class="text-sm font-medium text-muted-foreground hover:text-foreground"
            >
                Cancel
            </a>
        </div>
    </form>
</div>
