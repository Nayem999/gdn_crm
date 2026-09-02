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

    public function description(): string
    {
        return match ($this) {
            self::Own => 'Sees only records they own.',
            self::Team => 'Sees records owned by anyone whose active team matches theirs.',
            self::All => 'Sees every record in the CRM.',
        };
    }

    /**
     * Tailwind colour token used by status chips for this level.
     */
    public function color(): string
    {
        return match ($this) {
            self::Own => 'slate',
            self::Team => 'blue',
            self::All => 'emerald',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
