<?php

namespace App\Domain\Ingestion\Enums;

/**
 * Which way the data moves.
 *
 * Push and pull are genuinely different arrangements, not a preference: a push
 * source is somebody else's system calling us and needs a signed endpoint and a
 * secret (8.2, 8.3), while a pull source is us calling theirs on a schedule and
 * needs a URL, credentials and a cursor (8.8). The type decides which half of
 * the configuration is even meaningful, so it is chosen when the source is
 * created rather than inferred later.
 */
enum DataSourceType: string
{
    case Push = 'push';
    case Pull = 'pull';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push',
            self::Pull => 'Pull',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Push => 'Their system posts to us when something happens.',
            self::Pull => 'We fetch from their system on a schedule.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Push => 'indigo',
            self::Pull => 'cyan',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Push => 'arrow-down-to-line',
            self::Pull => 'arrow-up-from-line',
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
