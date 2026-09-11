<?php

namespace App\Domain\Workflows\DTOs;

use App\Domain\Workflows\Enums\WorkflowActionType;

/**
 * One step as a form submitted it.
 *
 * The config is kept as-is apart from being forced to a string-keyed array: its
 * shape depends on the action type, and 5.4 is what knows each one. What this
 * *does* guarantee is that the type is a real case of the enum — the type is
 * read back to decide which handler runs, so a stored row that could name
 * anything would be a row choosing what to execute.
 */
readonly class WorkflowActionData
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public WorkflowActionType $type,
        public array $config = [],
        public int $position = 0,
        public bool $isActive = true,
        public bool $stopOnFailure = true,
        /** The existing row this edits, or null for a new step. */
        public ?int $id = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes, int $position = 0): self
    {
        $config = $attributes['config'] ?? [];

        return new self(
            type: WorkflowActionType::tryFrom((string) ($attributes['type'] ?? ''))
                ?? WorkflowActionType::UpdateField,
            config: is_array($config) ? $config : [],
            // The submitted order is the stored order; a position carried in
            // the payload would let two steps claim the same slot.
            position: $position,
            isActive: (bool) ($attributes['is_active'] ?? true),
            stopOnFailure: (bool) ($attributes['stop_on_failure'] ?? true),
            id: isset($attributes['id']) && $attributes['id'] !== '' ? (int) $attributes['id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'type' => $this->type->value,
            'config' => $this->config,
            'position' => $this->position,
            'is_active' => $this->isActive,
            'stop_on_failure' => $this->stopOnFailure,
        ];
    }
}
