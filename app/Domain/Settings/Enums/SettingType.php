<?php

namespace App\Domain\Settings\Enums;

/**
 * How a stored setting's text value is read back and written down.
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Json = 'json';

    /**
     * Turn the stored text into the value the application should see.
     */
    public function cast(?string $stored): mixed
    {
        if ($stored === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Text => $stored,
            self::Integer => (int) $stored,
            self::Float => (float) $stored,
            self::Boolean => filter_var($stored, FILTER_VALIDATE_BOOLEAN),
            self::Json => json_decode($stored, true),
        };
    }

    /**
     * Turn an application value into the text to store.
     */
    public function serialise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Text => (string) $value,
            self::Integer => (string) (int) $value,
            self::Float => (string) (float) $value,
            self::Boolean => $value ? '1' : '0',
            self::Json => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * The validation rule this type implies, before the field adds its own.
     */
    public function baseRule(): string
    {
        return match ($this) {
            self::String => 'string',
            self::Text => 'string',
            self::Integer => 'integer',
            self::Float => 'numeric',
            self::Boolean => 'boolean',
            self::Json => 'array',
        };
    }
}
