@props(['condition', 'index', 'fields', 'groupIndex' => null])

@php
    use App\Domain\Shared\Enums\FilterFieldType;
    use App\Domain\Shared\Enums\FilterOperator;
    use App\Domain\Shared\Enums\FilterValueMode;

    $path = $groupIndex === null
        ? "filters.conditions.{$index}"
        : "filters.groups.{$groupIndex}.conditions.{$index}";

    $field = $fields[$condition['field'] ?? ''] ?? null;
    $operator = FilterOperator::tryFrom($condition['operator'] ?? '');
    $valueMode = $operator?->valueMode() ?? FilterValueMode::Single;

    $inputType = match ($field?->type) {
        FilterFieldType::Number => 'number',
        FilterFieldType::Date => 'date',
        default => 'text',
    };

    $inputClass = 'w-full rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent/40';
@endphp

<div class="flex flex-wrap items-center gap-2">
    <select class="{{ $inputClass }} sm:w-40" wire:model.live="{{ $path }}.field" aria-label="Filter field">
        @foreach ($fields as $option)
            <option value="{{ $option->key }}">{{ $option->label }}</option>
        @endforeach
    </select>

    <select class="{{ $inputClass }} sm:w-40" wire:model.live="{{ $path }}.operator" aria-label="Filter operator">
        @foreach ($field ? FilterOperator::optionsFor($field->type) : [] as $value => $optionLabel)
            <option value="{{ $value }}">{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if ($valueMode === FilterValueMode::None)
        <span class="text-sm text-muted-foreground">&mdash;</span>
    @elseif ($valueMode === FilterValueMode::Multiple)
        <select
            class="{{ $inputClass }} sm:w-56"
            wire:model.live="{{ $path }}.selected"
            multiple
            size="3"
            aria-label="Filter values"
        >
            @foreach ($field?->options ?? [] as $value => $optionLabel)
                <option value="{{ $value }}">{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif ($field?->type === FilterFieldType::Select)
        <select class="{{ $inputClass }} sm:w-48" wire:model.live="{{ $path }}.value" aria-label="Filter value">
            <option value="">Choose&hellip;</option>
            @foreach ($field->options as $value => $optionLabel)
                <option value="{{ $value }}">{{ $optionLabel }}</option>
            @endforeach
        </select>
    @else
        <input
            type="{{ $operator === FilterOperator::LastDays ? 'number' : $inputType }}"
            class="{{ $inputClass }} sm:w-40"
            wire:model.live.debounce.400ms="{{ $path }}.value"
            placeholder="{{ $operator === FilterOperator::LastDays ? 'Days' : 'Value' }}"
            aria-label="Filter value"
        />

        @if ($valueMode === FilterValueMode::Pair)
            <span class="text-sm text-muted-foreground">and</span>

            <input
                type="{{ $inputType }}"
                class="{{ $inputClass }} sm:w-40"
                wire:model.live.debounce.400ms="{{ $path }}.second_value"
                placeholder="Value"
                aria-label="Second filter value"
            />
        @endif
    @endif

    <button
        type="button"
        class="ml-auto rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
        wire:click="removeCondition({{ $index }}{{ $groupIndex === null ? '' : ', '.$groupIndex }})"
        aria-label="Remove this condition"
    >
        <x-icon name="lucide-trash-2" />
    </button>
</div>
