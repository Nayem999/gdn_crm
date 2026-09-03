<?php

namespace App\Domain\Accounts\Enums;

/**
 * How big an account is, by headcount band.
 *
 * Bands rather than a raw employee count: sales teams segment on the band, and
 * an exact headcount is rarely known or kept current.
 */
enum AccountSize: string
{
    case Micro = 'micro';
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Micro => '1-9 employees',
            self::Small => '10-49 employees',
            self::Medium => '50-249 employees',
            self::Large => '250-999 employees',
            self::Enterprise => '1,000+ employees',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Micro => 'Micro',
            self::Small => 'Small',
            self::Medium => 'Medium',
            self::Large => 'Large',
            self::Enterprise => 'Enterprise',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Micro => 'slate',
            self::Small => 'cyan',
            self::Medium => 'blue',
            self::Large => 'violet',
            self::Enterprise => 'fuchsia',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $size) {
            $options[$size->value] = $size->label();
        }

        return $options;
    }
}
