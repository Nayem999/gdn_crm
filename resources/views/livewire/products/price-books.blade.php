<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-foreground">Price books</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                A book holds the products whose price differs. Anything it does not list keeps its catalogue price,
                so a book only has to carry what changes.
            </p>
        </div>

        @can('create', App\Domain\Products\Models\PriceBook::class)
            <x-button type="button" wire:click="add">
                <x-icon name="lucide-plus" />
                New price book
            </x-button>
        @endcan
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    @if ($editing)
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">{{ $editingId ? 'Edit price book' : 'New price book' }}</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-form.label for="name" required>Name</x-form.label>
                    <x-form.input id="name" wire:model="name" :invalid="$errors->has('name')" placeholder="Reseller prices" />
                    <x-form.error for="name" />
                </div>

                <div>
                    <x-form.label for="validFrom">Starts</x-form.label>
                    <x-form.input id="validFrom" type="date" wire:model="validFrom" :invalid="$errors->has('validFrom')" />
                    <x-form.error for="validFrom" />
                </div>

                <div>
                    <x-form.label for="validTo">Ends</x-form.label>
                    <x-form.input id="validTo" type="date" wire:model="validTo" :invalid="$errors->has('validTo')" />
                    <x-form.error for="validTo" />
                    <p class="mt-1.5 text-xs text-muted-foreground">Inclusive. Leave both empty for a book with no end.</p>
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="description">Description</x-form.label>
                    <x-form.input id="description" wire:model="description" :invalid="$errors->has('description')" />
                    <x-form.error for="description" />
                </div>
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm text-foreground">
                <input type="checkbox" wire:model="isActive" class="rounded border-border text-accent focus:ring-accent/40" />
                In use
            </label>

            <div class="mt-4 flex items-center gap-3">
                <x-button type="button" wire:click="save">Save</x-button>
                <button type="button" wire:click="cancel" class="text-sm font-medium text-muted-foreground hover:text-foreground">Cancel</button>
            </div>
        </section>
    @endif

    <div class="space-y-3">
        @forelse ($this->books as $book)
            <section class="rounded-xl border border-border bg-card" wire:key="book-{{ $book->id }}">
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <button type="button" class="min-w-0 flex-1 text-left" wire:click="open({{ $book->id }})">
                        <span class="flex items-center gap-2">
                            <span class="font-medium text-foreground">{{ $book->name }}</span>

                            @if ($book->is_default)
                                <x-status-chip color="emerald" dot>Default</x-status-chip>
                            @endif

                            @unless ($book->is_active)
                                <x-status-chip color="slate">Off</x-status-chip>
                            @endunless

                            @if (! $book->appliesOn() && $book->is_active)
                                <x-status-chip color="amber">Outside its dates</x-status-chip>
                            @endif
                        </span>

                        <span class="mt-0.5 block text-xs text-muted-foreground">
                            {{ $book->entries_count }} {{ str('price')->plural($book->entries_count) }}
                            @if ($book->valid_from || $book->valid_to)
                                &middot; {{ $book->valid_from?->toFormattedDateString() ?? 'any time' }}
                                to {{ $book->valid_to?->toFormattedDateString() ?? 'no end' }}
                            @endif
                        </span>
                    </button>

                    <div class="flex items-center gap-1">
                        @can('update', $book)
                            @unless ($book->is_default)
                                <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground" wire:click="makeDefault({{ $book->id }})">
                                    Make default
                                </button>
                            @endunless

                            <button type="button" class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground" wire:click="edit({{ $book->id }})" aria-label="Edit {{ $book->name }}">
                                <x-icon name="lucide-pencil" />
                            </button>
                        @endcan

                        @can('delete', $book)
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                wire:click="delete({{ $book->id }})"
                                wire:confirm="Remove {{ $book->name }}? Its prices fall back to the catalogue."
                                aria-label="Remove {{ $book->name }}"
                            >
                                <x-icon name="lucide-trash-2" />
                            </button>
                        @endcan
                    </div>
                </div>

                @if ($openBookId === $book->id)
                    <div class="border-t border-border px-4 py-3">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[34rem] text-sm">
                                <thead class="text-left text-xs uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th class="py-2 font-semibold">Product</th>
                                        <th class="py-2 text-right font-semibold">Catalogue</th>
                                        <th class="py-2 text-right font-semibold">In this book</th>
                                        <th class="py-2"><span class="sr-only">Remove</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($this->entries as $entry)
                                        <tr wire:key="entry-{{ $entry->id }}">
                                            <td class="py-2">
                                                <span class="text-foreground">{{ $entry->product?->name ?? 'Removed product' }}</span>
                                                @if ($entry->product?->sku)
                                                    <span class="ml-2 text-xs text-muted-foreground">{{ $entry->product->sku }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2 text-right tabular-nums text-muted-foreground">
                                                {{ App\Domain\Settings\NumberFormat::format((float) ($entry->product?->list_price ?? 0), 2) }}
                                            </td>
                                            <td class="py-2 text-right font-medium tabular-nums text-foreground">
                                                {{ App\Domain\Settings\NumberFormat::format($entry->price(), 2) }}
                                            </td>
                                            <td class="py-2 text-right">
                                                @can('update', $book)
                                                    <button type="button" class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive" wire:click="removeEntry({{ $entry->id }})" aria-label="Remove this price">
                                                        <x-icon name="lucide-x" class="h-4 w-4" />
                                                    </button>
                                                @endcan
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-4 text-center text-muted-foreground">
                                                Nothing overridden yet — every product is at its catalogue price.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @can('update', $book)
                            <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <div class="min-w-0 flex-1" wire:key="entry-product-{{ $book->id }}-{{ $entryProductId }}">
                                    <x-form.label for="entryProductId">Product</x-form.label>
                                    <x-select
                                        name="entryProductId"
                                        :options="$this->addableProducts()"
                                        :selected="$entryProductId"
                                        placeholder="Choose a product"
                                        wire:model.live="entryProductId"
                                    />
                                    <x-form.error for="entryProductId" />
                                </div>

                                <div class="w-32">
                                    <x-form.label for="entryPrice">Price</x-form.label>
                                    <x-form.input id="entryPrice" type="number" step="0.01" min="0" wire:model="entryPrice" :invalid="$errors->has('entryPrice')" />
                                    <x-form.error for="entryPrice" />
                                </div>

                                <x-button type="button" wire:click="addEntry" class="mb-0.5">Set price</x-button>
                            </div>
                        @endcan
                    </div>
                @endif
            </section>
        @empty
            <x-empty-state
                icon="tags"
                heading="No price books yet"
                description="Everything is charged at its catalogue price. Add a book to override some of them for a customer group or a season."
            />
        @endforelse
    </div>
</div>
