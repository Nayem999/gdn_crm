<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Enums\SettingType;

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
            'sales' => [
                'label' => 'Sales',
                'icon' => 'file-text',
                'description' => 'The rules a quote has to satisfy before it can go to a customer.',
                'fields' => [
                    // A percentage of the whole document, not of a line: ten
                    // per cent off one line of twenty is not the concession ten
                    // per cent off everything is, and a per-line rule lets
                    // somebody give away half a quote in slices that each pass.
                    SettingField::numberSelect('max_discount_percent', 'Discount needing approval above', [
                        0 => 'No limit',
                        5 => '5%',
                        10 => '10%',
                        15 => '15%',
                        20 => '20%',
                        25 => '25%',
                        30 => '30%',
                        50 => '50%',
                    ], 0, 'A quote discounted by more than this cannot be sent until somebody approves it.'),
                ],
            ],
            'scheduling' => [
                'label' => 'Scheduling',
                'icon' => 'calendar-clock',
                'description' => 'The working week meetings are booked into, and how long a slot is.',
                'fields' => [
                    // A preset rather than seven checkboxes: a setting field is
                    // one typed value, and "which days" is a shape the registry
                    // does not carry. The presets cover the working weeks people
                    // actually keep.
                    SettingField::select('working_week', 'Working week', [
                        'mon_fri' => 'Monday to Friday',
                        'mon_sat' => 'Monday to Saturday',
                        'sun_thu' => 'Sunday to Thursday',
                        'sat_wed' => 'Saturday to Wednesday',
                        'all' => 'Every day',
                    ], 'mon_fri'),
                    new SettingField('day_starts_at', 'Working day starts', SettingType::String,
                        default: '09:00', help: '24-hour time, e.g. 09:00.', extraRules: 'date_format:H:i'),
                    new SettingField('day_ends_at', 'Working day ends', SettingType::String,
                        default: '17:30', help: '24-hour time, e.g. 17:30.', extraRules: 'date_format:H:i'),
                    // Integer rather than string selects: the keys of a map
                    // written with numeric labels are ints however they are
                    // quoted, and a String field's own "string" rule then
                    // rejects its own options — which the settings framework's
                    // guard test catches.
                    SettingField::numberSelect('slot_minutes', 'Booking slots every', [
                        15 => '15 minutes',
                        30 => '30 minutes',
                        60 => 'Hour',
                    ], 30),
                    SettingField::numberSelect('default_meeting_minutes', 'Default meeting length', [
                        15 => '15 minutes',
                        30 => '30 minutes',
                        45 => '45 minutes',
                        60 => '1 hour',
                        90 => '1 hour 30 minutes',
                    ], 30),
                ],
            ],
            'notifications' => [
                'label' => 'Notification limits',
                'icon' => 'bell',
                'description' => 'Quiet hours and the ceiling that stops a notification storm. Which notifications go out at all is set under Notification rules.',
                'fields' => [
                    SettingField::boolean('quiet_hours_enabled', 'Hold notifications overnight', false,
                        'In-app notifications always arrive; email, SMS and WhatsApp wait until quiet hours end.'),
                    new SettingField('quiet_hours_start', 'Quiet hours start', SettingType::String,
                        default: '21:00', help: '24-hour time, e.g. 21:00.', extraRules: 'date_format:H:i'),
                    new SettingField('quiet_hours_end', 'Quiet hours end', SettingType::String,
                        default: '07:00', help: '24-hour time, e.g. 07:00.', extraRules: 'date_format:H:i'),
                    new SettingField('rate_limit_per_hour', 'Maximum per person per hour', SettingType::Integer,
                        default: 60, help: 'Anything beyond this is skipped and recorded in the log.',
                        extraRules: 'min:1|max:1000'),
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
