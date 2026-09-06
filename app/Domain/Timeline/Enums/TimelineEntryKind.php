<?php

namespace App\Domain\Timeline\Enums;

/**
 * The three things a record's timeline is made of.
 *
 * The filter on the timeline is this enum, so a value arriving from the browser
 * either resolves to a case or is ignored — it never becomes a table name.
 */
enum TimelineEntryKind: string
{
    case Note = 'note';
    case Document = 'document';
    case History = 'history';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Document => 'Document',
            self::History => 'History',
        };
    }

    /**
     * The wording for the filter, which talks about a group rather than one
     * entry.
     */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Note => 'Notes',
            self::Document => 'Documents',
            self::History => 'History',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Note => 'lucide-message-square-text',
            self::Document => 'lucide-paperclip',
            self::History => 'lucide-history',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Note => 'amber',
            self::Document => 'cyan',
            self::History => 'slate',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = [];

        foreach (self::cases() as $kind) {
            $options[$kind->value] = $kind->pluralLabel();
        }

        return $options;
    }
}
