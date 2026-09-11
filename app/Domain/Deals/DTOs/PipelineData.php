<?php

namespace App\Domain\Deals\DTOs;

use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\PipelineModules;

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
        public string $module,
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

        $module = (string) ($attributes['module'] ?? Pipeline::DEALS);

        return new self(
            // A module the registry does not list is not turned into a table
            // name — it falls back to deals, and the action refuses it.
            module: PipelineModules::has($module) ? $module : '',
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
