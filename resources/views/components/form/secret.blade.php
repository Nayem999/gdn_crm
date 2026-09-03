@props([
    'id',
    'stored' => false,
    'replacing' => false,
    'field' => null,
    'invalid' => false,
])

{{-- Write-only: a stored secret is shown as dots and never sent to the browser.
     "Replace" swaps in an empty password box; "Cancel" leaves the stored one
     alone. --}}
<div class="flex flex-wrap items-center gap-2">
    @if ($stored && ! $replacing)
        <span
            class="inline-flex flex-1 items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
            aria-label="A value is stored and hidden"
        >
            <x-icon name="lucide-lock" class="h-4 w-4" />
            ••••••••
        </span>

        <x-button type="button" variant="secondary" wire:click="replace('{{ $field }}')">
            Replace
        </x-button>

        <x-button type="button" variant="secondary" wire:click="clearSecret('{{ $field }}')">
            <x-icon name="lucide-trash-2" class="h-4 w-4" />
            <span class="sr-only">Remove stored value for </span>Remove
        </x-button>
    @else
        <div class="flex-1">
            <x-form.password
                :id="$id"
                :invalid="$invalid"
                autocomplete="new-password"
                placeholder="{{ $stored ? 'Enter the replacement value' : 'Not set' }}"
                {{ $attributes }}
            />
        </div>

        @if ($stored)
            <x-button type="button" variant="secondary" wire:click="cancelReplace('{{ $field }}')">
                Cancel
            </x-button>
        @endif
    @endif
</div>
