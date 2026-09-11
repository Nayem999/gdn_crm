<div>
    <div class="mb-6">
        <a href="{{ route('custom-modules.index', $moduleKey) }}" wire:navigate
           class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <x-icon name="lucide-arrow-left" class="h-4 w-4" />
            {{ $module->plural_name }}
        </a>

        <h1 class="mt-2 text-2xl font-semibold text-foreground">
            {{ $this->isEditing() ? 'Edit '.strtolower($module->name) : 'New '.strtolower($module->name) }}
        </h1>
    </div>

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border bg-card p-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="cr-name" required>{{ $module->title_label }}</x-form.label>
                    <x-form.input id="cr-name" wire:model="name" class="mt-1" />
                    <x-form.error for="name" class="mt-1" />
                </div>

                <div>
                    <x-form.label for="cr-owner" required>Owner</x-form.label>
                    <x-select
                        name="cr_owner"
                        :options="$this->ownerOptions()"
                        :selected="$owner_id"
                        wire:model="owner_id"
                        class="mt-1"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">Record visibility follows the owner.</p>
                    <x-form.error for="owner_id" class="mt-1" />
                </div>
            </div>
        </section>

        {{-- Everything beyond the title is a custom field, rendered by the same
             partial the five built-in forms use. --}}
        <x-custom-fields :form="$this" />

        <div class="flex flex-wrap items-center gap-3">
            <x-button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                {{ $this->isEditing() ? 'Save changes' : 'Create '.strtolower($module->name) }}
            </x-button>

            <a href="{{ route('custom-modules.index', $moduleKey) }}" wire:navigate
               class="text-sm font-medium text-muted-foreground hover:text-foreground">Cancel</a>
        </div>
    </form>
</div>
