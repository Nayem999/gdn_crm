<?php

namespace App\Domain\Deals\DTOs;

/**
 * A pipeline and its stages as the form submitted them, already validated.
 *
 * The stages arrive in display order, and that order is what the action writes
 * to `position` — the browser never sends a position number of its own.
 */
readonly class PipelineData
{
    /**
     * @param  array<int, StageData>  $stages
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public bool $isDefault = false,
        public array $stages = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $description = $attributes['description'] ?? null;
        $stages = is_array($attributes['stages'] ?? null) ? $attributes['stages'] : [];

        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            description: $description === null || trim((string) $description) === ''
                ? null
                : trim((string) $description),
            isDefault: (bool) ($attributes['is_default'] ?? false),
            stages: array_values(array_map(
                fn (array $stage) => StageData::fromArray($stage),
                array_filter($stages, 'is_array')
            )),
        );
    }
}
