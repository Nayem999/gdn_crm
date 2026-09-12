<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300">
                <x-icon name="lucide-package" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Products</h1>
                <p class="mt-1 text-sm text-muted-foreground">What you sell, what it costs, and what you charge for it.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('products.pricing')
                <a
                    href="{{ route('settings.price-books') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-muted"
                >
                    <x-icon name="lucide-tags" />
                    Price books
                </a>
            @endcan

            @can('create', App\Domain\Products\Models\Product::class)
                <a
                    href="{{ route('products.create') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
                >
                    <x-icon name="lucide-plus" />
                    Add product
                </a>
            @endcan
        </div>
    </div>

    @if ($error)
        <div class="mb-4">
            <x-alert variant="error">{{ $error }}</x-alert>
        </div>
    @endif

    {{-- Quick filter chips, kept beside the builder rather than inside it so
         one cannot silently overwrite the other. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach ([
            'active' => 'In the catalogue',
            'services' => 'Services only',
            'bundles' => 'Bundles only',
        ] as $chip => $label)
            <button
                type="button"
                wire:click="setQuickFilter('{{ $chip }}')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                    'border-accent bg-accent/10 text-accent' => $quickFilter === $chip,
                    'border-border text-muted-foreground hover:text-foreground' => $quickFilter !== $chip,
                ])
                aria-pressed="{{ $quickFilter === $chip ? 'true' : 'false' }}"
            >
                {{ $label }}
            </button>
        @endforeach

        @if ($quickFilter !== '' || $this->hasActiveFilters())
            <button type="button" wire:click="clearAllFilters" class="text-xs font-medium text-muted-foreground hover:text-destructive">
                Clear all
            </button>
        @endif
    </div>

    <x-data-view
        :view="$this"
        :records="$this->rows"
        search-placeholder="Search products…"
        empty-icon="package"
        empty-heading="Nothing in the catalogue yet"
        empty-description="Add the products and services you sell, then set what you charge for them."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Products\Models\Product::class)
                <a href="{{ route('products.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Add your first product
                </a>
            @endcan
        </x-slot:empty-actions>
    </x-data-view>
</div>
