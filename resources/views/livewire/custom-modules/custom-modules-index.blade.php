@php
    use App\Domain\Shared\UI\ChipPalette;
    $modules = $this->modules;
@endphp

<div>
    <x-settings-shell
        heading="Custom modules"
        description="Modules you add here get their own records, all four views, filters and exports — no migration, and no code."
        active="settings.custom-modules"
    >
        <div
            x-data="{ message: '', tone: 'success' }"
            x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
            x-show="message"
            x-cloak
            class="mb-4"
        >
            <template x-if="tone === 'error'"><x-alert variant="error"><span x-text="message"></span></x-alert></template>
            <template x-if="tone !== 'error'"><x-alert variant="success"><span x-text="message"></span></x-alert></template>
        </div>

        @can('create', App\Domain\CustomModules\Models\CustomModule::class)
            @unless ($editing)
                <button
                    type="button"
                    wire:click="add"
                    class="mb-5 inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-primary-foreground hover:opacity-90"
                >
                    <x-icon name="lucide-plus" />
                    Add module
                </button>
            @endunless
        @endcan

        @if ($editing)
            <form wire:submit="save" class="mb-6 rounded-xl border border-border bg-card p-5">
                <h3 class="text-base font-semibold text-foreground">
                    {{ $editingId === null ? 'New module' : 'Edit module' }}
                </h3>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-form.label for="cm-name" required>Name (one record)</x-form.label>
                        <x-form.input id="cm-name" wire:model="name" placeholder="Project" class="mt-1" />
                        <x-form.error for="name" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="cm-plural">Name (many)</x-form.label>
                        <x-form.input id="cm-plural" wire:model="pluralName" placeholder="Projects" class="mt-1" />
                        <p class="mt-1 text-xs text-muted-foreground">Left blank, this is the singular with an s.</p>
                        <x-form.error for="pluralName" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="cm-title" required>What the title field is called</x-form.label>
                        <x-form.input id="cm-title" wire:model="titleLabel" placeholder="Project name" class="mt-1" />
                        <x-form.error for="titleLabel" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="cm-color" required>Colour</x-form.label>
                        <x-select
                            name="cm_color"
                            :options="$this->colorOptions()"
                            :selected="$color"
                            wire:model="color"
                            class="mt-1"
                        />
                        <x-form.error for="color" class="mt-1" />
                    </div>

                    <div>
                        <x-form.label for="cm-icon" required>Icon</x-form.label>
                        <x-form.input id="cm-icon" wire:model="icon" placeholder="box" class="mt-1" />
                        <p class="mt-1 text-xs text-muted-foreground">A Lucide icon name, such as <code>folder</code>.</p>
                        <x-form.error for="icon" class="mt-1" />
                    </div>

                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 text-sm text-foreground">
                            <input type="checkbox" wire:model="isActive" class="rounded border-border text-accent focus:ring-accent/40">
                            Shown in the navigation
                        </label>
                    </div>
                </div>

                <div class="mt-4">
                    <x-form.label for="cm-description">Description</x-form.label>
                    <x-form.input id="cm-description" wire:model="description" class="mt-1" />
                    <x-form.error for="description" class="mt-1" />
                </div>

                <div class="mt-5 flex items-center justify-end gap-2 border-t border-border pt-4">
                    <button type="button" wire:click="cancel"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                        Save module
                    </button>
                </div>
            </form>
        @endif

        @if ($modules->isEmpty())
            <x-empty-state
                icon="box"
                heading="No custom modules yet"
                description="Add one and it appears in the navigation with its own list, filters and export. Give it fields under Custom fields."
            />
        @else
            <ul class="space-y-2">
                @foreach ($modules as $module)
                    <li wire:key="module-{{ $module->id }}"
                        @class([
                            'flex flex-wrap items-center gap-3 rounded-xl border p-3',
                            'border-border bg-card' => $module->is_active,
                            'border-dashed border-border bg-muted/30' => ! $module->is_active,
                        ])>
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ ChipPalette::classes($module->color) }}">
                            <x-icon :name="'lucide-' . $module->icon" class="h-4 w-4" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-medium text-foreground">{{ $module->plural_name }}</span>
                                <span class="font-mono text-xs text-muted-foreground">{{ $module->moduleKey() }}</span>
                                @unless ($module->is_active)
                                    <span class="{{ ChipPalette::BASE }} {{ ChipPalette::classes('slate') }}">Hidden</span>
                                @endunless
                            </div>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ $module->records_count }} {{ \Illuminate\Support\Str::plural('record', $module->records_count) }}
                                &middot; title field &ldquo;{{ $module->title_label }}&rdquo;
                            </p>
                        </div>

                        @can('update', $module)
                            <div class="flex shrink-0 items-center gap-1">
                                <a href="{{ route('settings.custom-fields', ['module' => $module->moduleKey()]) }}" wire:navigate
                                   class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">Fields</a>

                                <button type="button" wire:click="toggleActive({{ $module->id }})"
                                        class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">
                                    {{ $module->is_active ? 'Hide' : 'Show' }}
                                </button>

                                <button type="button" wire:click="edit({{ $module->id }})"
                                        class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground">Edit</button>

                                <button
                                    type="button"
                                    wire:click="delete({{ $module->id }})"
                                    wire:confirm="Remove {{ $module->plural_name }}? Its {{ $module->records_count }} {{ \Illuminate\Support\Str::plural('record', $module->records_count) }} and every field you defined on it go with it. Hiding it keeps them."
                                    class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-destructive/10 hover:text-destructive">Remove</button>
                            </div>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </x-settings-shell>
</div>
