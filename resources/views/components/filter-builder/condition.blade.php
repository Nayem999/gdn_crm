@props(['condition', 'index', 'fields', 'siblings', 'groupIndex' => null])

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

    $fieldOptions = collect($fields)->mapWithKeys(fn ($option) => [$option->key => $option->label])->all();

    $inputType = match ($field?->type) {
        FilterFieldType::Number => 'number',
        FilterFieldType::Date => 'date',
        default => 'text',
    };

    /**
     * <x-select> keeps Tom Select behind wire:ignore, so Livewire can neither
     * update a dropdown's options nor move its selection. Changing the key is
     * what makes Livewire replace the node so Tom Select rebuilds, and two
     * things here need that:
     *
     * - The comparison list and the value's shape depend on the chosen field,
     *   so both carry it.
     * - Rows are keyed by index, so removing one hands a row's DOM to the
     *   condition that slid into its place. The sibling count changes whenever
     *   indices shift, which is exactly when a rebuild is needed — and, unlike
     *   keying on the value itself, it does not tear the dropdown down while
     *   somebody is picking from it.
     */
    $generation = $path.'-'.$siblings;
    $operatorKey = $generation.'-op-'.($condition['field'] ?? '');
    $shapeKey = $generation.'-val-'.($condition['field'] ?? '').'-'.($condition['operator'] ?? '');
@endphp

<div class="flex flex-wrap items-start gap-2">
    <div class="w-full sm:w-40" wire:key="{{ $generation }}-field">
        <x-select
            name="{{ $path }}.field"
            :options="$fieldOptions"
            :selected="$condition['field'] ?? null"
            placeholder="Field&hellip;"
            aria-label="Filter field"
            wire:model.live="{{ $path }}.field"
        />
    </div>

    <div class="w-full sm:w-44" wire:key="{{ $operatorKey }}">
        <x-select
            name="{{ $path }}.operator"
            :options="$field ? FilterOperator::optionsFor($field->type) : []"
            :selected="$condition['operator'] ?? null"
            placeholder="Comparison&hellip;"
            aria-label="Filter operator"
            wire:model.live="{{ $path }}.operator"
        />
    </div>

    <div class="w-full sm:w-48" wire:key="{{ $shapeKey }}">
        @if ($valueMode === FilterValueMode::None)
            <span class="block py-2 text-sm text-muted-foreground">&mdash;</span>
        @elseif ($valueMode === FilterValueMode::Multiple)
            <x-select
                name="{{ $path }}.selected"
                :options="$field?->options ?? []"
                :selected="$condition['selected'] ?? []"
                multiple
                placeholder="Choose values&hellip;"
                aria-label="Filter values"
                wire:model.live="{{ $path }}.selected"
            />
        @elseif ($field?->type === FilterFieldType::Select)
            <x-select
                name="{{ $path }}.value"
                :options="$field->options"
                :selected="$condition['value'] ?? null"
                placeholder="Choose&hellip;"
                aria-label="Filter value"
                wire:model.live="{{ $path }}.value"
            />
        @else
            <x-form.input
                type="{{ $operator === FilterOperator::LastDays ? 'number' : $inputType }}"
                wire:model.live.debounce.400ms="{{ $path }}.value"
                placeholder="{{ $operator === FilterOperator::LastDays ? 'Days' : 'Value' }}"
                aria-label="Filter value"
            />

            @if ($valueMode === FilterValueMode::Pair)
                <div class="mt-1.5 flex items-center gap-2">
                    <span class="text-sm text-muted-foreground">and</span>

                    <x-form.input
                        type="{{ $inputType }}"
                        wire:model.live.debounce.400ms="{{ $path }}.second_value"
                        placeholder="Value"
                        aria-label="Second filter value"
                    />
                </div>
            @endif
        @endif
    </div>

    <button
        type="button"
        class="ml-auto mt-1 rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
        wire:click="removeCondition({{ $index }}{{ $groupIndex === null ? '' : ', '.$groupIndex }})"
        aria-label="Remove this condition"
    >
        <x-icon name="lucide-trash-2" />
    </button>
</div>
