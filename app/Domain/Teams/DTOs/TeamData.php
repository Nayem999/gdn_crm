<?php

namespace App\Domain\Teams\DTOs;

readonly class TeamData
{
    /**
     * @param  array<int, int>  $memberIds
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?int $parentId = null,
        public array $memberIds = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parent_id' => $this->parentId,
        ];
    }
}
