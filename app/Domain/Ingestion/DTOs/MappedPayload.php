<?php

namespace App\Domain\Ingestion\DTOs;

/**
 * What a source's mappings make of one payload.
 *
 * Kept apart from the act of writing it, so the dry run on the mapping screen
 * and the pipeline that actually persists are looking at the same object
 * produced by the same code. A preview computed by a second implementation is
 * a preview of something else.
 */
readonly class MappedPayload
{
    /**
     * @param  array<string, string|null>  $row  module fields
     * @param  array<string, string|null>  $customFields  custom field keys
     * @param  array<int, string>  $missing  required, and absent
     * @param  array<int, string>  $ignored  mappings naming nothing real
     */
    public function __construct(
        public array $row = [],
        public array $customFields = [],
        public array $missing = [],
        public array $ignored = [],
    ) {}

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /**
     * Everything that would be written, for a preview to show in one table.
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return [...$this->row, ...$this->customFields];
    }
}
