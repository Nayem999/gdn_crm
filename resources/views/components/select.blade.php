@props([
    // Original prop API from task 1.1 — do not change these names.
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => 'Select an option...',
    'multiple' => false,
    'required' => false,

    // Task 1.7 additions, all optional.
    'searchMethod' => null,
    'searchUrl' => null,
    'preload' => false,
    'createEvent' => null,
    'dependsOn' => null,
    'hint' => null,
    'error' => null,

    // Task 2.4/2.5 addition. A single select offers no way back to "nothing
    // chosen": Tom Select drops the empty option unless allowEmptyOption is on,
    // so an "Any status" row would not be selectable. Filters that need an
    // unset state get a clear button on the control instead.
    'clearable' => false,
])

@php
    /**
     * Options accept two shapes: the flat ['value' => 'Label'] map the rest of
     * the app already uses, or rich rows carrying a description and colour.
     */
    $rows = collect($options)->map(function ($option, $key) {
        if (is_array($option)) {
            return [
                'value' => (string) ($option['value'] ?? $key),
                'label' => (string) ($option['label'] ?? $option['value'] ?? $key),
                'description' => $option['description'] ?? null,
                'color' => $option['color'] ?? null,
                'disabled' => (bool) ($option['disabled'] ?? false),
            ];
        }

        return [
            'value' => (string) $key,
            'label' => (string) $option,
            'description' => null,
            'color' => null,
            'disabled' => false,
        ];
    })->values();

    $selectedValues = collect(is_array($selected) ? $selected : [$selected])
        ->filter(fn ($value) => $value !== null && $value !== '')
        ->map(fn ($value) => (string) $value)
        ->all();

    $config = array_filter([
        'multiple' => $multiple,
        'placeholder' => $placeholder,
        'searchMethod' => $searchMethod,
        'searchUrl' => $searchUrl,
        'preload' => $preload,
        'createEvent' => $createEvent,
        'dependsOn' => $dependsOn,
        'clearable' => $clearable,
    ], fn ($value) => $value !== null && $value !== false);
@endphp

<div>
    @if ($label)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-foreground">
            {{ $label }}
            @if ($required)
                <span class="text-destructive">*</span>
            @endif
        </label>
    @endif

    {{-- wire:ignore keeps Livewire from stomping on the DOM Tom Select builds. --}}
    <div wire:ignore x-data="tomSelectField(@js($config))">
        <select
            {{ $attributes->merge(['class' => 'w-full']) }}
            id="{{ $name }}"
            name="{{ $name }}{{ $multiple ? '[]' : '' }}"
            x-ref="select"
            @if ($multiple) multiple @endif
            @if ($required) required @endif
        >
            @if (! $multiple)
                <option value=""></option>
            @endif

            @foreach ($rows as $row)
                {{-- Tom Select only carries extra option data through data-data. --}}
                <option
                    value="{{ $row['value'] }}"
                    data-data="{{ json_encode([
                        'value' => $row['value'],
                        'text' => $row['label'],
                        'description' => $row['description'],
                        'color' => $row['color'],
                    ], JSON_THROW_ON_ERROR) }}"
                    @disabled($row['disabled'])
                    @selected(in_array($row['value'], $selectedValues, true))
                >{{ $row['label'] }}</option>
            @endforeach
        </select>
    </div>

    @if ($error)
        <p class="mt-1.5 text-sm text-destructive">{{ $error }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
