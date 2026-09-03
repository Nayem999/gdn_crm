<div>
    <x-settings-shell heading="Company" description="Your organisation's details, as they appear on branded documents." active="settings.company">
    <div
        x-data="{ show: false }"
        x-show="show"
        x-init="Livewire.on('company-profile-saved', () => { show = true; setTimeout(() => show = false, 3000) })"
        x-transition
        x-cloak
        class="mb-6"
    >
        <x-alert variant="success">Company profile saved.</x-alert>
    </div>

    <form wire:submit="save" class="space-y-8">
        <section>
            <h2 class="text-base font-semibold text-foreground">Company details</h2>
            <p class="mt-1 text-sm text-muted-foreground">This information appears on quotes, invoices, and other branded documents.</p>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="name" required>Company name</x-form.label>
                    <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" />
                    <x-form.error for="name" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="logo">Logo</x-form.label>
                    <div class="flex items-center gap-4">
                        @if ($logo && $logo->isPreviewable())
                            <img src="{{ $logo->temporaryUrl() }}" alt="Logo preview" class="h-14 w-14 rounded-lg border border-border object-cover">
                        @elseif ($this->existingLogoUrl())
                            <img src="{{ $this->existingLogoUrl() }}" alt="Company logo" class="h-14 w-14 rounded-lg border border-border object-cover">
                        @else
                            <div class="flex h-14 w-14 items-center justify-center rounded-lg border border-dashed border-border text-muted-foreground">
                                <x-lucide-image class="h-6 w-6" aria-hidden="true" />
                            </div>
                        @endif

                        <input id="logo" type="file" wire:model="logo" accept="image/*" class="text-sm text-muted-foreground">
                    </div>
                    <div wire:loading wire:target="logo" class="mt-1 text-xs text-muted-foreground">Uploading...</div>
                    <x-form.error for="logo" />
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-base font-semibold text-foreground">Address</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="addressLine1">Address line 1</x-form.label>
                    <x-form.input id="addressLine1" wire:model="addressLine1" :invalid="$errors->has('addressLine1')" />
                    <x-form.error for="addressLine1" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="addressLine2">Address line 2</x-form.label>
                    <x-form.input id="addressLine2" wire:model="addressLine2" :invalid="$errors->has('addressLine2')" />
                    <x-form.error for="addressLine2" />
                </div>

                <div>
                    <x-form.label for="city">City</x-form.label>
                    <x-form.input id="city" wire:model="city" :invalid="$errors->has('city')" />
                    <x-form.error for="city" />
                </div>

                <div>
                    <x-form.label for="state">State / Province</x-form.label>
                    <x-form.input id="state" wire:model="state" :invalid="$errors->has('state')" />
                    <x-form.error for="state" />
                </div>

                <div>
                    <x-form.label for="postalCode">Postal code</x-form.label>
                    <x-form.input id="postalCode" wire:model="postalCode" :invalid="$errors->has('postalCode')" />
                    <x-form.error for="postalCode" />
                </div>

                <div>
                    <x-form.label for="country">Country</x-form.label>
                    <x-form.input id="country" wire:model="country" :invalid="$errors->has('country')" />
                    <x-form.error for="country" />
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-base font-semibold text-foreground">Localization</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-select
                    name="timezone"
                    label="Timezone"
                    :options="$this->timezoneOptions()"
                    :selected="$timezone"
                    wire:model="timezone"
                    required
                />

                <x-select
                    name="currency"
                    label="Currency"
                    :options="$this->currencyOptions()"
                    :selected="$currency"
                    wire:model="currency"
                    required
                />

                <x-select
                    name="fiscalYearStartMonth"
                    label="Fiscal year starts"
                    :options="$this->monthOptions()"
                    :selected="$fiscalYearStartMonth"
                    wire:model="fiscalYearStartMonth"
                    required
                />
            </div>

            <x-form.error for="timezone" class="mt-2" />
            <x-form.error for="currency" class="mt-2" />
            <x-form.error for="fiscalYearStartMonth" class="mt-2" />
        </section>

        <div class="flex justify-end border-t border-border pt-6">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Save changes
            </x-button>
        </div>
    </form>
    </x-settings-shell>
</div>
