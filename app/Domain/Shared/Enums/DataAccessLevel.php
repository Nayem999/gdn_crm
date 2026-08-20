<?php

namespace App\Domain\Shared\Enums;

enum DataAccessLevel: string
{
    case Own = 'own';
    case Team = 'team';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own records only',
            self::Team => 'Team records',
            self::All => 'All records',
        };
    }
}
