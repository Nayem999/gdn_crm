<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300">
                <x-icon name="lucide-contact" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">Contacts</h1>
                <p class="mt-1 text-sm text-muted-foreground">The people you deal with at each organisation.</p>
            </div>
        </div>

        @can('create', App\Domain\Contacts\Models\Contact::class)
            <a
                href="{{ route('contacts.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                Add contact
            </a>
        @endcan
    </div>

    <div
        x-data="{ message: '' }"
        x-on:contact-deleted.window="message = `${$event.detail.name} was removed.`; setTimeout(() => message = '', 3000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <x-alert variant="success"><span x-text="message"></span></x-alert>
    </div>

    @if ($this->filteredAccountName())
        <div class="mb-4">
            <x-alert variant="info">
                Showing contacts at <strong>{{ $this->filteredAccountName() }}</strong>.
                <button type="button" wire:click="clearAllFilters" class="ml-1 font-medium underline">Show all</button>
            </x-alert>
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @foreach ([
            'mine' => 'My contacts',
            'primary' => 'Primary contacts',
            'unlinked' => 'No account yet',
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

        @if ($quickFilter !== '' || $this->hasActiveFilters() || $accountId !== null)
            <button type="button" wire:click="clearAllFilters" class="text-xs font-medium text-muted-foreground hover:text-destructive">
                Clear all
            </button>
        @endif
    </div>

    <x-data-view
        :view="$this"
        :records="$this->rows"
        search-placeholder="Search contacts…"
        empty-icon="contact"
        empty-heading="No contacts yet"
        empty-description="Add the people you speak to at each account."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\Contacts\Models\Contact::class)
                <a href="{{ route('contacts.create') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Add your first contact
                </a>
            @endcan
        </x-slot:empty-actions>

        <x-slot:bulk-actions>
            @can('create', App\Domain\Contacts\Models\Contact::class)
                <button
                    type="button"
                    wire:click="deleteSelected"
                    wire:confirm="Remove the selected contacts?"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-destructive hover:bg-destructive/10"
                >
                    <x-icon name="lucide-trash-2" class="h-4 w-4" />
                    Remove
                </button>
            @endcan
        </x-slot:bulk-actions>
    </x-data-view>
</div>
