@props([
    'name',
    'label' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => 'Select an option...',
    'multiple' => false,
    'required' => false,
])

<div>
    @if ($label)
        <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-foreground">
            {{ $label }}
            @if ($required)
                <span class="text-destructive">*</span>
            @endif
        </label>
    @endif

    <div wire:ignore x-data="tomSelectField({ multiple: {{ $multiple ? 'true' : 'false' }}, placeholder: @js($placeholder) })">
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

            @foreach ($options as $value => $optionLabel)
                <option value="{{ $value }}" @selected(is_array($selected) ? in_array((string) $value, array_map('strval', $selected), true) : (string) $value === (string) $selected)>
                    {{ $optionLabel }}
                </option>
            @endforeach
        </select>
    </div>
</div>
