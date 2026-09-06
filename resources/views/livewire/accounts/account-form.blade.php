<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="{{ route('accounts.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <x-icon name="lucide-arrow-left" class="h-4 w-4" />
                Accounts
            </a>
            <h1 class="mt-2 text-2xl font-semibold text-foreground">
                {{ $this->isEditing() ? 'Edit account' : 'New account' }}
            </h1>
        </div>
    </div>

    <x-duplicate-warning
        :matches="$this->draftDuplicates()"
        :route="fn ($record) => route('accounts.show', $record)"
    />

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border bg-card p-5 sm:p-6">
            <h2 class="text-base font-semibold text-foreground">Organisation</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="name" required>Account name</x-form.label>
                    <x-form.input id="name" wire:model.live.debounce.500ms="name" :invalid="$errors->has('name')" />
                    <x-form.error for="name" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="legal_name">Registered legal name</x-form.label>
                    <x-form.input id="legal_name" wire:model.live.debounce.500ms="legal_name" :invalid="$errors->has('legal_name')" />
                    <x-form.error for="legal_name" />
                </div>

                <x-select
                    name="industry"
                    label="Industry"
                    :options="$industries"
                    :selected="$industry"
                    placeholder="Choose an industry…"
                    :error="$errors->first('industry')"
                    wire:model="industry"
                />

                <x-select
                    name="size"
                    label="Size"
                    :options="$sizes"
                    :selected="$size"
                    placeholder="Choose a size…"
                    :error="$errors->first('size')"
                    wire:model="size"
                />

                <div>
                    <x-form.label for="annual_revenue">Annual revenue</x-form.label>
                    <x-form.input id="annual_revenue" type="number" step="0.01" min="0" wire:model="annual_revenue" :invalid="$errors->has('annual_revenue')" />
                    <x-form.error for="annual_revenue" />
                </div>

                <div>
                    <x-form.label for="website">Website</x-form.label>
                    <x-form.input id="website" wire:model="website" placeholder="example.com" :invalid="$errors->has('website')" />
                    <x-form.error for="website" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="description">Description</x-form.label>
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
            <h2 class="text-base font-semibold text-foreground">Contact</h2>

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
            <h2 class="text-base font-semibold text-foreground">Ownership &amp; hierarchy</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-select
                    name="owner_id"
                    label="Owner"
                    :options="$owners"
                    :selected="$owner_id"
                    placeholder="Choose an owner…"
                    :error="$errors->first('owner_id')"
                    required
                    hint="Who this account belongs to. Record visibility follows the owner."
                    wire:model="owner_id"
                />

                <x-select
                    name="parent_id"
                    label="Parent account"
                    :options="$parents"
                    :selected="$parent_id"
                    placeholder="No parent"
                    :error="$errors->first('parent_id')"
                    hint="An account cannot sit beneath itself or one of its own subsidiaries."
                    wire:model="parent_id"
                />
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                {{ $this->isEditing() ? 'Save changes' : 'Create account' }}
            </x-button>

            <a
                href="{{ $this->isEditing() ? route('accounts.show', $this->accountId) : route('accounts.index') }}"
                wire:navigate
                class="text-sm font-medium text-muted-foreground hover:text-foreground"
            >
                Cancel
            </a>
        </div>
    </form>
</div>
