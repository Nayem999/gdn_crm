<?php

namespace App\Domain\Access\DTOs;

use App\Domain\Shared\Enums\DataAccessLevel;

readonly class RoleData
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public string $name,
        public DataAccessLevel $dataAccessLevel,
        public array $permissions = [],
    ) {}
}
