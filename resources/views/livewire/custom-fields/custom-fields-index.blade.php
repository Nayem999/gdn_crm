@php
    use App\Domain\CustomFields\Enums\CustomFieldType;
    use App\Domain\Shared\UI\ChipPalette;

    $fields = $this->fields;
    $type = $this->currentType();
@endphp

{{-- Livewire needs a single root element, and the shell is not one. --}}
<div>
    <x-settings-shell
        heading="Custom fields"
        description="Extra fields on each module's records. They are data, not columns — adding one needs no migration."
        active="settings.custom-fields"
    >
    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <template x-if="tone === 'error'">
            <x-alert variant="error"><span x-text="message"></span></x-alert>
        </template>
        <template x-if="tone !== 'error'">
            <x-alert variant="success"><span x-text="message"></span></x-alert>
        </template>
    </div>

    {{-- Which module's fields are on screen. --}}
    <div class="mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="Module">
        @foreach ($this->moduleOptions() as $key => $label)
            <button
                type="button"
                wire:key="module-{{ $key }}"
                wire:click="selectModule('{{ $key }}')"
                @class([
                    'rounded-full border px-3 py-1 text-sm font-medium transition-colors',
                    'border-accent bg-accent/10 text-accent' => $module === $key,
                    'border-border text-muted-foreground hover:text-foreground' => $module !== $key,
                ])
                aria-pressed="{{ $module === $key ? 'true' : 'false' }}"
            >
                {{ $label }}
            </button>
        @endforeach

        @if ($this->canManage() && ! $editing)
            <button
                type="button"
                wire:click="add"
                class="ml-auto inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                Add field
            </button>
        @endif
    </div>

    {{-- The editor. --}}
    @if ($editing)
        <form wire:submit="save" class="mb-6 rounded-xl border border-border bg-card p-5">
            <h3 class="text-base font-semibold text-foreground">
                {{ $editingId === null ? 'New field' : 'Edit field' }}
            </h3>

            @if ($editingId !== null)
                <p class="mt-1 text-xs text-muted-foreground">
                    The label can change; the key behind it cannot, because saved filters and
                    import mappings refer to it.
                </p>
            @endif

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="cf-label" required>Label</x-form.label>
                    <x-form.input id="cf-label" wire:model="label" placeholder="Industry sector" class="mt-1" />
                    <x-form.error for="label" class="mt-1" />
                </div>

                <div>
                    <x-form.label for="cf-type" required>Type</x-form.label>
                    <x-select
                        name="cf_type"
                        :options="$this->typeOptions()"
                        :selected="$type->value"
                        wire:model.live="type"
                        class="mt-1"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">{{ $type->help() }}</p>
                    <x-form.error for="type" class="mt-1" />
                </div>
            </div>

            <div class="mt-4">
                <x-form.label for="cf-help">Help text</x-form.label>
                <x-form.input id="cf-help" wire:model="help" placeholder="Shown under the field on the form." class="mt-1" />
                <x-form.error for="help" class="mt-1" />
            </div>

            {{-- Choices, for the two types that have them. --}}
            @if ($type->hasOptions())
                <div class="mt-4">
                    <x-form.label>Choices</x-form.label>

                    <div class="mt-1 space-y-2">
                        @foreach ($options as $index => $option)
                            <div class="flex items-center gap-2" wire:key="option-{{ $index }}">
                                <x-form.input
                                    wire:model="options.{{ $index }}.label"
                                    placeholder="Manufacturing"
                                    class="flex-1"
                                    aria-label="Choice {{ $index + 1 }}"
                                />

                                @if ($option['key'] ?? '')
                                    <span class="shrink-0 font-mono text-xs text-muted-foreground">{{ $option['key'] }}</span>
                                @endif

                                <button
                                    type="button"
                                    wire:click="removeOption({{ $index }})"
                                    class="shrink-0 rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-destructive"
                                    aria-label="Remove choice {{ $index + 1 }}"
                                >
                                    <x-icon name="lucide-x" class="h-4 w-4" />
                                </button>
                            </div>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        wire:click="addOption"
                        class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline"
                    >
                        <x-icon name="lucide-plus" class="h-3.5 w-3.5" />
                        Add a choice
                    </button>

                    <x-form.error for="options" class="mt-1" />
                    @foreach ($options as $index => $option)
                        <x-form.error :for="'options.'.$index.'.label'" class="mt-1" />
                    @endforeach
                </div>
            @endif

            @if ($type->isLookup())
                <div class="mt-4 sm:w-1/2">
                    <x-form.label for="cf-lookup" required>Module to look up</x-form.label>
                    <x-select
                        name="cf_lookup"
                        :options="$this->moduleOptions()"
                        :selected="$lookupModule"
                        placeholder="Choose a module"
                        wire:model="lookupModule"
                        class="mt-1"
                    />
                    <x-form.error for="lookupModule" class="mt-1" />
                </div>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-5">
                <label class="flex items-center gap-2 text-sm text-foreground">
                    <input type="checkbox" wire:model="isRequired" class="rounded border-border text-accent focus:ring-accent/40">
                    Required
                </label>

                <label class="flex items-center gap-2 text-sm text-foreground">
                    <input type="checkbox" wire:model="isActive" class="rounded border-border text-accent focus:ring-accent/40">
                    Shown on the form
                </label>
            </div>

            <div class="mt-5 flex items-center justify-end gap-2 border-t border-border pt-4">
                <button
                    type="button"
                    wire:click="cancel"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                >Cancel</button>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90 disabled:opacity-60"
                >
                    <x-icon name="lucide-loader-circle" class="h-4 w-4 animate-spin" wire:loading wire:target="save" />
                    Save field
                </button>
            </div>
        </form>
    @endif

    {{-- The fields on this module. --}}
    @if ($fields->isEmpty())
        <x-empty-state
            icon="sliders-horizontal"
            heading="No custom fields yet"
            description="Add one and it appears on every {{ strtolower(\App\Domain\CustomFields\CustomFieldRegistry::label($module)) }} record."
        />
    @else
        <ul class="space-y-2">
            @foreach ($fields as $index => $field)
                @php($fieldType = $field->type())
                <li
                    wire:key="field-{{ $field->id }}"
                    @class([
                        'flex flex-wrap items-center gap-3 rounded-xl border p-3',
                        'border-border bg-card' => $field->is_active,
                        'border-dashed border-border bg-muted/30' => ! $field->is_active,
                    ])
                >
                    @if ($this->canManage())
                        <div class="flex shrink-0 flex-col">
                            <button
                                type="button"
                                wire:click="moveUp({{ $field->id }})"
                                @disabled($index === 0)
                                class="rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-30"
                                aria-label="Move {{ $field->label }} up"
                            >
                                <x-icon name="lucide-chevron-up" class="h-3.5 w-3.5" />
                            </button>
                            <button
                                type="button"
                                wire:click="moveDown({{ $field->id }})"
                                @disabled($index === $fields->count() - 1)
                                class="rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-30"
                                aria-label="Move {{ $field->label }} down"
                            >
                                <x-icon name="lucide-chevron-down" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    @endif

                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ ChipPalette::classes('slate') }}">
                        <x-icon :name="'lucide-' . $fieldType->icon()" class="h-4 w-4" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span class="font-medium text-foreground">{{ $field->label }}</span>
                            <span class="font-mono text-xs text-muted-foreground">{{ $field->key }}</span>

                            <span class="{{ ChipPalette::BASE }} {{ ChipPalette::classes('slate') }}">
                                {{ $fieldType->label() }}
                            </span>

                            @if ($field->is_required)
                                <span class="{{ ChipPalette::BASE }} {{ ChipPalette::classes('amber') }}">Required</span>
                            @endif

                            @unless ($field->is_active)
                                <span class="{{ ChipPalette::BASE }} {{ ChipPalette::classes('slate') }}">Hidden</span>
                            @endunless
                        </div>

                        <p class="mt-0.5 text-xs text-muted-foreground">
                            @if ($fieldType->isLookup() && $field->lookup_module)
                                Looks up {{ strtolower(\App\Domain\CustomFields\CustomFieldRegistry::label($field->lookup_module)) }}.
                            @elseif ($fieldType->hasOptions())
                                {{ count($field->optionKeys()) }} {{ \Illuminate\Support\Str::plural('choice', count($field->optionKeys())) }}:
                                {{ implode(', ', array_values($field->optionMap())) }}
                            @elseif ($field->help)
                                {{ $field->help }}
                            @endif

                            <span class="ml-1">
                                &middot; {{ $field->values_count }} {{ \Illuminate\Support\Str::plural('answer', $field->values_count) }}
                            </span>
                        </p>
                    </div>

                    @if ($this->canManage())
                        <div class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                wire:click="toggleActive({{ $field->id }})"
                                class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            >{{ $field->is_active ? 'Hide' : 'Show' }}</button>

                            <button
                                type="button"
                                wire:click="edit({{ $field->id }})"
                                class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            >Edit</button>

                            {{-- The count is in the confirmation because the
                                 answers go with the field, and "hide" is the
                                 reversible option sitting right beside it. --}}
                            <button
                                type="button"
                                wire:click="delete({{ $field->id }})"
                                wire:confirm="Remove {{ $field->label }}? Its {{ $field->values_count }} {{ \Illuminate\Support\Str::plural('answer', $field->values_count) }} will go with it. Hiding it keeps them."
                                class="rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                            >Remove</button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-settings-shell>
</div>
