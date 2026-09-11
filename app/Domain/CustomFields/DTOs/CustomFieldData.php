<?php

namespace App\Domain\CustomFields\DTOs;

use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\VisibilityCondition;

/**
 * A field definition as a form submitted it, already normalised.
 *
 * `key` is deliberately absent: it is derived from the label on create and never
 * changes afterwards, so no payload can carry one. See CustomField::keyFrom.
 */
readonly class CustomFieldData
{
    /**
     * @param  array<int, array{key: string, label: string}>  $options
     */
    public function __construct(
        public string $module,
        public string $label,
        public CustomFieldType $type,
        public ?string $help = null,
        public ?string $section = null,
        public bool $isFullWidth = false,
        /** @var array{field: string, operator: string, value: string|null}|null */
        public ?array $visibleWhen = null,
        public bool $isRequired = false,
        public bool $isActive = true,
        public array $options = [],
        public ?string $lookupModule = null,
        public ?string $defaultValue = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $type = CustomFieldType::tryFrom((string) ($attributes['type'] ?? '')) ?? CustomFieldType::Text;
        $module = (string) ($attributes['module'] ?? '');

        return new self(
            // A module the registry does not list is not turned into a class —
            // it falls back to the first one the registry offers, and the
            // action refuses it outright.
            module: CustomFieldRegistry::has($module) ? $module : '',
            label: trim((string) ($attributes['label'] ?? '')),
            type: $type,
            help: self::text($attributes, 'help'),
            section: self::text($attributes, 'section'),
            isFullWidth: (bool) ($attributes['is_full_width'] ?? false),
            visibleWhen: self::condition($attributes['visible_when'] ?? null),
            isRequired: (bool) ($attributes['is_required'] ?? false),
            isActive: (bool) ($attributes['is_active'] ?? true),
            options: $type->hasOptions() ? self::normaliseOptions($attributes['options'] ?? []) : [],
            // Only a lookup carries one, so a type change cannot leave a
            // dangling module behind on a text field.
            lookupModule: $type->isLookup() ? self::lookupModule($attributes) : null,
            defaultValue: self::text($attributes, 'default_value'),
        );
    }

    /**
     * Options with a permanent key each.
     *
     * An option that already has a key keeps it — that is what stops renaming
     * a choice from orphaning every record that chose it. A new one gets a key
     * derived from its label, never one from the browser.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function normaliseOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalised = [];
        $taken = [];

        foreach ($options as $option) {
            $label = trim((string) (is_array($option) ? ($option['label'] ?? '') : $option));

            if ($label === '') {
                continue;
            }

            $existing = is_array($option) ? trim((string) ($option['key'] ?? '')) : '';

            // An existing key is honoured only when it is well formed and not
            // already used in this list; anything else is treated as new. Same
            // rule SavePipelineAction applies to a stage key.
            $key = $existing !== '' && preg_match('/^[a-z0-9_]+$/', $existing) === 1 && ! in_array($existing, $taken, true)
                ? $existing
                : CustomField::optionKey($label, $taken);

            $taken[] = $key;
            $normalised[] = ['key' => $key, 'label' => $label];
        }

        return $normalised;
    }

    /**
     * A condition, normalised through the value object so a half-filled one —
     * a field chosen but no operator yet — is stored as nothing rather than as
     * a rule that can never match.
     *
     * @return array{field: string, operator: string, value: string|null}|null
     */
    private static function condition(mixed $submitted): ?array
    {
        $condition = VisibilityCondition::fromStored($submitted);

        if ($condition === null) {
            return null;
        }

        // An operator that compares needs something to compare against.
        if ($condition->operator->needsValue() && $condition->value === null) {
            return null;
        }

        return $condition->toArray();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function lookupModule(array $attributes): ?string
    {
        $module = (string) ($attributes['lookup_module'] ?? '');

        return CustomFieldRegistry::has($module) ? $module : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * The columns a create or update writes. `key` is not among them.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'label' => $this->label,
            'type' => $this->type->value,
            'help' => $this->help,
            'section' => $this->section,
            'is_full_width' => $this->isFullWidth,
            'visible_when' => $this->visibleWhen,
            'is_required' => $this->isRequired,
            'is_active' => $this->isActive,
            'options' => $this->type->hasOptions() ? $this->options : null,
            'lookup_module' => $this->lookupModule,
            'default_value' => $this->defaultValue,
        ];
    }
}
