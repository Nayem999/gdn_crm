<?php

namespace App\Domain\Contacts\Enums;

/**
 * Which part of an organisation a contact sits in.
 *
 * An enum rather than free text: it is the one contact field with few enough
 * values to filter cleanly and to group a kanban board by, and sales teams
 * route by it. Job title stays free text, because that genuinely varies.
 */
enum Department: string
{
    case Executive = 'executive';
    case Sales = 'sales';
    case Marketing = 'marketing';
    case Finance = 'finance';
    case Operations = 'operations';
    case Technology = 'technology';
    case People = 'people';
    case Legal = 'legal';
    case Support = 'support';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Executive => 'Executive',
            self::Sales => 'Sales',
            self::Marketing => 'Marketing',
            self::Finance => 'Finance',
            self::Operations => 'Operations',
            self::Technology => 'Technology',
            self::People => 'People & HR',
            self::Legal => 'Legal',
            self::Support => 'Support',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Executive => 'fuchsia',
            self::Sales => 'emerald',
            self::Marketing => 'violet',
            self::Finance => 'teal',
            self::Operations => 'amber',
            self::Technology => 'indigo',
            self::People => 'rose',
            self::Legal => 'cyan',
            self::Support => 'blue',
            self::Other => 'slate',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $department) {
            $options[$department->value] = $department->label();
        }

        return $options;
    }
}
