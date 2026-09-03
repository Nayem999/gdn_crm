<?php

namespace App\Domain\Settings;

/**
 * The canonical list of settings the application recognises.
 *
 * Nothing outside this registry can be written: SaveSettingsAction resolves
 * every submitted key here first, so a tampered form cannot invent a setting,
 * change a field's type, or flip a secret into a readable one. It is to Settings
 * what PermissionCatalogue is to roles.
 *
 * Later phases add their own groups here — email providers (7.2), SMS and
 * WhatsApp (7.6), API and webhooks (7.9), data sources (8.1).
 */
final class SettingsRegistry
{
    /**
     * @return array<string, array{label: string, icon: string, description: string, fields: array<int, SettingField>}>
     */
    public static function groups(): array
    {
        return [
            'localisation' => [
                'label' => 'Localisation',
                'icon' => 'globe',
                'description' => 'How dates, times and numbers are written throughout the application.',
                'fields' => [
                    SettingField::select('date_format', 'Date format', [
                        'd/m/Y' => '31/12/2026',
                        'm/d/Y' => '12/31/2026',
                        'Y-m-d' => '2026-12-31',
                        'j M Y' => '31 Dec 2026',
                        'M j, Y' => 'Dec 31, 2026',
                    ], 'j M Y'),
                    SettingField::select('time_format', 'Time format', [
                        'H:i' => '23:59',
                        'g:i A' => '11:59 PM',
                    ], 'H:i'),
                    SettingField::select('week_starts_on', 'Week starts on', [
                        'monday' => 'Monday',
                        'sunday' => 'Sunday',
                        'saturday' => 'Saturday',
                    ], 'monday'),
                    // Stored as tokens rather than the glyph itself: Laravel's
                    // "required" rule trims strings, so a literal space could
                    // never be saved. NumberFormat turns a token into a glyph.
                    SettingField::select('thousands_separator', 'Thousands separator', [
                        'comma' => 'Comma — 1,234,567',
                        'dot' => 'Full stop — 1.234.567',
                        'space' => 'Space — 1 234 567',
                        'none' => 'None — 1234567',
                    ], 'comma'),
                    SettingField::select('decimal_separator', 'Decimal separator', [
                        'dot' => 'Full stop — 1234.56',
                        'comma' => 'Comma — 1234,56',
                    ], 'dot'),
                ],
            ],
            'storage' => [
                'label' => 'Storage',
                'icon' => 'hard-drive',
                'description' => 'Where uploaded files are kept. Leave S3 blank to keep using local disk storage.',
                'fields' => [
                    SettingField::select('driver', 'Storage driver', [
                        'local' => 'Local disk',
                        's3' => 'Amazon S3 (or compatible)',
                    ], 'local'),
                    SettingField::text('s3_bucket', 'Bucket'),
                    SettingField::text('s3_region', 'Region', 'For example eu-west-1.'),
                    SettingField::text('s3_endpoint', 'Endpoint', 'Only needed for S3-compatible providers.'),
                    SettingField::secret('s3_key', 'Access key ID'),
                    SettingField::secret('s3_secret', 'Secret access key'),
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function groupKeys(): array
    {
        return array_keys(self::groups());
    }

    public static function hasGroup(string $group): bool
    {
        return array_key_exists($group, self::groups());
    }

    /**
     * @return array{label: string, icon: string, description: string, fields: array<int, SettingField>}|null
     */
    public static function group(string $group): ?array
    {
        return self::groups()[$group] ?? null;
    }

    /**
     * Every field in a group, keyed by its own key.
     *
     * @return array<string, SettingField>
     */
    public static function fields(string $group): array
    {
        $declared = self::group($group);

        if ($declared === null) {
            return [];
        }

        $fields = [];

        foreach ($declared['fields'] as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    /**
     * Resolve a dotted setting name such as "storage.s3_key". The first segment
     * is the group; whatever follows is the key within it.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function split(string $name): ?array
    {
        $position = strpos($name, '.');

        if ($position === false) {
            return null;
        }

        return [substr($name, 0, $position), substr($name, $position + 1)];
    }

    /**
     * The declared field behind a dotted name, or null if there is no such
     * setting. Callers treat null as "refuse", never as "create it".
     */
    public static function find(string $name): ?SettingField
    {
        $parts = self::split($name);

        if ($parts === null) {
            return null;
        }

        return self::fields($parts[0])[$parts[1]] ?? null;
    }

    public static function isSecret(string $name): bool
    {
        $field = self::find($name);

        return $field !== null && $field->secret;
    }

    /**
     * Every declared dotted name.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::groups() as $group => $declared) {
            foreach ($declared['fields'] as $field) {
                $names[] = $group.'.'.$field->key;
            }
        }

        return $names;
    }
}
