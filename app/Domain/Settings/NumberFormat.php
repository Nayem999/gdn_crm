<?php

namespace App\Domain\Settings;

/**
 * Turns the localisation settings into an actual formatted number.
 *
 * Separators are stored as tokens ("comma", "space") rather than the glyph,
 * because Laravel's "required" rule trims strings and a literal space could
 * never be saved.
 */
final class NumberFormat
{
    private const SEPARATORS = [
        'comma' => ',',
        'dot' => '.',
        'space' => ' ',
        'none' => '',
    ];

    public static function separator(string $token, string $fallback = ','): string
    {
        return self::SEPARATORS[$token] ?? $fallback;
    }

    public static function thousands(): string
    {
        return self::separator((string) settings('localisation.thousands_separator', 'comma'));
    }

    public static function decimal(): string
    {
        return self::separator((string) settings('localisation.decimal_separator', 'dot'), '.');
    }

    public static function format(float|int $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, self::decimal(), self::thousands());
    }
}
