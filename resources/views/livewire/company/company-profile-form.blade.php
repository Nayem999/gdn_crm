<div>
    <div
        x-data="{ show: false }"
        x-show="show"
        x-init="Livewire.on('company-profile-saved', () => { show = true; setTimeout(() => show = false, 3000) })"
        x-transition
        x-cloak
        class="mb-6 rounded-lg border border-accent/30 bg-accent/10 px-4 py-3 text-sm text-accent"
    >
        Company profile saved.
    </div>

    <form wire:submit="save" class="space-y-8">
        <section>
            <h2 class="text-base font-semibold text-foreground">Company details</h2>
            <p class="mt-1 text-sm text-muted-foreground">This information appears on quotes, invoices, and other branded documents.</p>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="mb-1.5 block text-sm font-medium text-foreground">Company name <span class="text-destructive">*</span></label>
                    <input
                        id="name"
                        type="text"
                        wire:model="name"
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                    >
                    @error('name') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-foreground">Logo</label>
                    <div class="flex items-center gap-4">
                        @if ($logo)
                            <img src="{{ $logo->temporaryUrl() }}" alt="Logo preview" class="h-14 w-14 rounded-lg border border-border object-cover">
                        @elseif ($this->existingLogoUrl())
                            <img src="{{ $this->existingLogoUrl() }}" alt="Company logo" class="h-14 w-14 rounded-lg border border-border object-cover">
                        @else
                            <div class="flex h-14 w-14 items-center justify-center rounded-lg border border-dashed border-border text-muted-foreground">
                                <x-lucide-image class="h-6 w-6" aria-hidden="true" />
                            </div>
                        @endif

                        <input type="file" wire:model="logo" accept="image/*" class="text-sm text-muted-foreground">
                    </div>
                    <div wire:loading wire:target="logo" class="mt-1 text-xs text-muted-foreground">Uploading...</div>
                    @error('logo') <p class="mt-1 text-sm text-destructive">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-base font-semibold text-foreground">Address</h2>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="addressLine1" class="mb-1.5 block text-sm font-medium text-foreground">Address line 1</label>
                    <input id="addressLine1" type="text" wire:model="addressLine1" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40">
                </div>

                <div class="sm:col-span-2">
                    <label for="addressLine2" class="mb-1.5 block text-sm font-medium text-foreground">Address line 2</label>
                    <input id="addressLine2" type="text" wire:model="addressLine2" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40">
                </div>

                <div>
                    <label for="city" class="mb-1.5 block text-sm font-medium text-foreground">City</label>
                    <input id="city" type="text" wire:model="city" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40">
                </div>

                <div>
                    <label for="state" class="mb-1.5 block text-sm font-medium text-foreground">State / Province</label>
                    <input id="state" type="text" wire:model="state" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40">
                </div>

                <div>
                    <label for="postalCode" class="mb-1.5 block text-sm font-medium text-foreground">Postal code</label>
                    <input id="postalCode" type="text" wire:model="postalCode" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40">
                </div>

                <div>
                    <label for="country" class="mb-1.5 block text-sm font-medium text-foreground">Country</label>
                    <input id="country" type="text" wire:model="country" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40">
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
            @error('timezone') <p class="mt-2 text-sm text-destructive">{{ $message }}</p> @enderror
            @error('currency') <p class="mt-2 text-sm text-destructive">{{ $message }}</p> @enderror
            @error('fiscalYearStartMonth') <p class="mt-2 text-sm text-destructive">{{ $message }}</p> @enderror
        </section>

        <div class="flex justify-end border-t border-border pt-6">
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90 disabled:opacity-60"
            >
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                Save changes
            </button>
        </div>
    </form>
</div>
