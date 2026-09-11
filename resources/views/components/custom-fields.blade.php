@props([
    // The Livewire component using WithCustomFieldForm.
    'form',
    'heading' => 'Additional details',
])

@php
    use App\Domain\CustomFields\Enums\CustomFieldType;

    $definitions = $form->customFieldDefinitions();
    $viewer = auth()->user();
@endphp

{{-- Nothing at all until an administrator has defined something, so a module
     with no custom fields looks exactly as it did before they existed. --}}
@if ($definitions->isNotEmpty())
    <section {{ $attributes->merge(['class' => 'rounded-xl border border-border bg-card p-5']) }}>
        <h2 class="text-base font-semibold text-foreground">{{ $heading }}</h2>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ($definitions as $field)
                @php
                    $type = $field->type();
                    $model = 'customFields.'.$field->key;
                    $id = 'cf-input-'.$field->key;
                    // A multi-select and a long dropdown want the full width;
                    // a date or a tickbox does not.
                    $wide = $type->isMultiple();
                @endphp

                <div wire:key="cf-{{ $field->id }}" @class(['sm:col-span-2' => $wide])>
                    @if ($type === CustomFieldType::Checkbox)
                        <label class="flex items-start gap-2 text-sm text-foreground">
                            <input
                                type="checkbox"
                                id="{{ $id }}"
                                wire:model="{{ $model }}"
                                class="mt-0.5 rounded border-border text-accent focus:ring-accent/40"
                            >
                            <span>
                                {{ $field->label }}
                                @if ($field->is_required)
                                    <span class="text-destructive" aria-hidden="true">*</span>
                                @endif
                            </span>
                        </label>
                    @else
                        <x-form.label :for="$id" :required="$field->is_required">{{ $field->label }}</x-form.label>

                        @switch (true)
                            @case ($type === CustomFieldType::Select)
                                <x-select
                                    :name="'cf_'.$field->key"
                                    :options="$field->optionMap()"
                                    :selected="$form->customFields[$field->key] ?? null"
                                    placeholder="Choose one"
                                    clearable
                                    :wire:model="$model"
                                    class="mt-1"
                                />
                                @break

                            @case ($type === CustomFieldType::MultiSelect)
                                <x-select
                                    :name="'cf_'.$field->key"
                                    :options="$field->optionMap()"
                                    :selected="$form->customFields[$field->key] ?? []"
                                    placeholder="Choose any"
                                    multiple
                                    :wire:model="$model"
                                    class="mt-1"
                                />
                                @break

                            @case ($type === CustomFieldType::Lookup)
                                <x-select
                                    :name="'cf_'.$field->key"
                                    :options="$viewer ? $form->customFieldLookupOptions($field, $viewer) : []"
                                    :selected="$form->customFields[$field->key] ?? null"
                                    placeholder="Choose a record"
                                    clearable
                                    :wire:model="$model"
                                    class="mt-1"
                                />
                                @break

                            @case ($type === CustomFieldType::Date)
                                <x-form.input type="date" :id="$id" wire:model="{{ $model }}" class="mt-1" />
                                @break

                            @case ($type === CustomFieldType::Number || $type === CustomFieldType::Currency)
                                <x-form.input
                                    type="number"
                                    step="0.01"
                                    :id="$id"
                                    wire:model="{{ $model }}"
                                    class="mt-1"
                                />
                                @break

                            @default
                                <x-form.input :id="$id" wire:model="{{ $model }}" class="mt-1" />
                        @endswitch
                    @endif

                    @if ($field->help)
                        <p class="mt-1 text-xs text-muted-foreground">{{ $field->help }}</p>
                    @endif

                    <x-form.error :for="$model" class="mt-1" />
                </div>
            @endforeach
        </div>
    </section>
@endif
