<?php

namespace App\Domain\CustomModules\DTOs;

/**
 * A module definition as a form submitted it.
 *
 * `key` is absent on purpose: it is derived from the name on create and never
 * changes, because a URL, every custom field row on the module and any saved
 * view all refer to it.
 */
readonly class CustomModuleData
{
    public function __construct(
        public string $name,
        public string $pluralName,
        public string $titleLabel = 'Name',
        public string $icon = 'box',
        public string $color = 'slate',
        public ?string $description = null,
        public bool $isActive = true,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $plural = trim((string) ($attributes['plural_name'] ?? ''));
        $description = trim((string) ($attributes['description'] ?? ''));
        $titleLabel = trim((string) ($attributes['title_label'] ?? ''));

        return new self(
            name: $name,
            // A plural nobody typed is the singular with an s, which is right
            // often enough to be worth not asking for.
            pluralName: $plural === '' ? str($name)->plural()->toString() : $plural,
            titleLabel: $titleLabel === '' ? 'Name' : $titleLabel,
            icon: trim((string) ($attributes['icon'] ?? '')) ?: 'box',
            color: trim((string) ($attributes['color'] ?? '')) ?: 'slate',
            description: $description === '' ? null : $description,
            isActive: (bool) ($attributes['is_active'] ?? true),
        );
    }

    /**
     * The columns a create or update writes. `key` is not among them.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'plural_name' => $this->pluralName,
            'title_label' => $this->titleLabel,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'is_active' => $this->isActive,
        ];
    }
}
