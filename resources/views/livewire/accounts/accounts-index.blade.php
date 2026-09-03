<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                <x-icon name="lucide-building-2" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Accounts</h1>
                <p class="mt-1 text-sm text-muted-foreground">The organisations you do business with.</p>
            </div>
        </div>

        @can('create', App\Domain\Accounts\Models\Account::class)
            <a
                href="{{ route('accounts.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                Add account
            </a>
        @endcan
    </div>

    <div
        x-data="{ message: '' }"
        x-on:account-deleted.window="message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    {{-- Quick filter chips, kept beside the builder rather than inside it so
         one cannot silently overwrite the other. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach ([
            'mine' => 'My accounts',
            'subsidiaries' => 'Subsidiaries only',
            'this_week' => 'Added this week',
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
        search-placeholder="Search accounts…"
        empty-icon="building-2"
        empty-heading="No accounts yet"
        empty-description="Add the organisations you sell to, and their subsidiaries."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Accounts\Models\Account::class)
                <a href="{{ route('accounts.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Add your first account
                </a>
            @endcan
        </x-slot:empty-actions>

        <x-slot:bulk-actions>
            @can('create', App\Domain\Accounts\Models\Account::class)
                <button
                    type="button"
                    wire:click="deleteSelected"
                    wire:confirm="Remove the selected accounts? Their subsidiaries move to the top level."
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-destructive hover:bg-destructive/10"
                >
                    <x-icon name="lucide-trash-2" class="h-4 w-4" />
                    Remove
                </button>
            @endcan
        </x-slot:bulk-actions>
    </x-data-view>
</div>
