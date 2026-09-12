<?php

namespace App\Domain\Chat\Enums;

enum ChatAuthor: string
{
    case Visitor = 'visitor';
    case Agent = 'agent';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Visitor => 'Visitor',
            self::Agent => 'Agent',
            self::System => 'System',
        };
    }
}
