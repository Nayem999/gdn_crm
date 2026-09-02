<?php

namespace App\Domain\Users\DTOs;

readonly class UserData
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password = null,
        public ?int $roleId = null,
        public ?int $currentTeamId = null,
    ) {}

    /**
     * Attributes for persisting, excluding the password (hashed separately) and
     * the role (assigned through spatie/permission rather than a column).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'current_team_id' => $this->currentTeamId,
        ];
    }
}
