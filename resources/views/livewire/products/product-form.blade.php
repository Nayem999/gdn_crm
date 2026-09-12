@php
    use App\Domain\Products\Enums\ProductKind;
@endphp

<div class="mx-auto max-w-3xl space-y-6 pb-16">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">
                {{ $productId ? 'Edit product' : 'Add product' }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                What it is, what it costs, and what it lists at.
            </p>
        </div>

        <a
            href="{{ route('products.index') }}"
            wire:navigate
            class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
            <x-icon name="lucide-arrow-left" />
            Catalogue
        </a>
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What it is</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="name" required>Name</x-form.label>
                    <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" />
                    <x-form.error for="name" />
                </div>

                <div>
                    <x-form.label for="sku">SKU</x-form.label>
                    <x-form.input id="sku" wire:model="sku" :invalid="$errors->has('sku')" placeholder="AB-1234" />
                    <x-form.error for="sku" />
                    <p class="mt-1.5 text-xs text-muted-foreground">Optional, and unique across the catalogue.</p>
                </div>

                <div>
                    <x-form.label for="category">Category</x-form.label>
                    <x-form.input id="category" wire:model="category" :invalid="$errors->has('category')" />
                    <x-form.error for="category" />
                </div>

                <div wire:key="kind-{{ $kind }}">
                    <x-form.label for="kind" required>Type</x-form.label>
                    <x-select name="kind" :options="$this->kindOptions()" :selected="$kind" wire:model.live="kind" />
                    <x-form.error for="kind" />
                </div>

                <div wire:key="unit-{{ $unit }}">
                    <x-form.label for="unit" required>Sold by</x-form.label>
                    <x-select name="unit" :options="$this->unitOptions()" :selected="$unit" wire:model.live="unit" />
                    <x-form.error for="unit" />
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

        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What it costs</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                The list price is the catalogue price. A price book can override it without changing this.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <x-form.label for="listPrice" required>List price</x-form.label>
                    <x-form.input id="listPrice" type="number" step="0.01" min="0" wire:model="listPrice" :invalid="$errors->has('listPrice')" />
                    <x-form.error for="listPrice" />
                </div>

                @if ((ProductKind::tryFrom($kind) ?? ProductKind::Product)->hasOwnCost())
                    <div>
                        <x-form.label for="costPrice">Unit cost</x-form.label>
                        <x-form.input id="costPrice" type="number" step="0.01" min="0" wire:model="costPrice" :invalid="$errors->has('costPrice')" />
                        <x-form.error for="costPrice" />
                    </div>
                @endif

                <div>
                    <x-form.label for="taxRate">Tax rate</x-form.label>
                    <x-form.input id="taxRate" type="number" step="0.01" min="0" max="100" wire:model="taxRate" :invalid="$errors->has('taxRate')" />
                    <x-form.error for="taxRate" />
                    <p class="mt-1.5 text-xs text-muted-foreground">Per cent. Leave empty to let the document decide.</p>
                </div>
            </div>
        </section>

        @if ($this->isBundle())
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">What is in it</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    A bundle is priced on its own, so it can be worth less than its parts. Bundles cannot contain other bundles.
                </p>

                @error('components')
                    <p class="mt-2 text-sm text-destructive">{{ $message }}</p>
                @enderror

                <div class="mt-4 space-y-2">
                    @foreach ($components as $index => $part)
                        <div class="flex flex-wrap items-end gap-2" wire:key="component-{{ $index }}">
                            <div class="min-w-0 flex-1" wire:key="component-{{ $index }}-product-{{ $part['product_id'] ?? '' }}">
                                <x-form.label :for="'components.'.$index.'.product_id'">Product</x-form.label>
                                <x-select
                                    :name="'components.'.$index.'.product_id'"
                                    :options="$this->componentOptions()"
                                    :selected="$part['product_id'] ?? ''"
                                    placeholder="Choose a product"
                                    wire:model.live="components.{{ $index }}.product_id"
                                />
                                <x-form.error :for="'components.'.$index.'.product_id'" />
                            </div>

                            <div class="w-28">
                                <x-form.label :for="'components.'.$index.'.quantity'">Quantity</x-form.label>
                                <x-form.input
                                    :id="'components.'.$index.'.quantity'"
                                    type="number"
                                    step="0.001"
                                    min="0.001"
                                    wire:model.live="components.{{ $index }}.quantity"
                                />
                                <x-form.error :for="'components.'.$index.'.quantity'" />
                            </div>

                            <button
                                type="button"
                                class="mb-1 rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-destructive"
                                wire:click="removeComponent({{ $index }})"
                                aria-label="Take this out of the bundle"
                            >
                                <x-icon name="lucide-trash-2" />
                            </button>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
                    <button type="button" class="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline" wire:click="addComponent">
                        <x-icon name="lucide-plus" />
                        Add a product
                    </button>

                    <p class="text-xs text-muted-foreground">
                        Parts at list price:
                        <span class="font-semibold tabular-nums text-foreground">
                            {{ App\Domain\Settings\NumberFormat::format($this->componentTotal(), 2) }}
                        </span>
                    </p>
                </div>
            </section>
        @endif

        @if ($this->hasCustomFields())
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">More</h2>
                <div class="mt-4">
                    <x-custom-fields :form="$this" />
                </div>
            </section>
        @endif

        <section class="rounded-xl border border-border bg-card p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div wire:key="owner-{{ $ownerId }}">
                    <x-form.label for="ownerId" required>Owner</x-form.label>
                    <x-select
                        name="ownerId"
                        :options="collect($this->ownerOptions())->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()"
                        :selected="$ownerId"
                        wire:model.live="ownerId"
                    />
                    <x-form.error for="ownerId" />
                </div>

                <label class="flex items-start gap-2 self-end pb-2 text-sm text-foreground">
                    <input type="checkbox" wire:model="isActive" class="mt-0.5 rounded border-border text-accent focus:ring-accent/40" />
                    <span>
                        In the catalogue
                        <span class="block text-xs text-muted-foreground">
                            Switch off instead of removing when something is no longer sold but still sits inside a bundle.
                        </span>
                    </span>
                </label>
            </div>
        </section>

        <div class="flex items-center gap-3">
            <x-button type="submit">{{ $productId ? 'Save product' : 'Add product' }}</x-button>

            <a href="{{ route('products.index') }}" wire:navigate class="text-sm font-medium text-muted-foreground hover:text-foreground">
                Cancel
            </a>
        </div>
    </form>
</div>
