<?php

namespace App\Domain\Ingestion\Enums;

/**
 * How we prove who we are to the system we are fetching from.
 *
 * Four, because that is what APIs in the wild actually ask for. Anything more
 * exotic — OAuth flows, signed requests — is a different task with its own
 * token lifecycle, not a fifth case here.
 */
enum PullAuth: string
{
    case None = 'none';
    case Bearer = 'bearer';
    case Basic = 'basic';
    /** A named header, for the APIs that invented their own. */
    case Header = 'header';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Bearer => 'Bearer token',
            self::Basic => 'Username and password',
            self::Header => 'A header of their choosing',
        };
    }

    public function needsSecret(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether the "name" field means anything — a username for basic, a header
     * name for a custom one.
     */
    public function needsName(): bool
    {
        return $this === self::Basic || $this === self::Header;
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
