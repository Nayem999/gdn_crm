<?php

namespace App\Domain\Timeline\Enums;

/**
 * The five things a record's timeline is made of.
 *
 * The filter on the timeline is this enum, so a value arriving from the browser
 * either resolves to a case or is ignored — it never becomes a table name.
 *
 * `Activity` here is a scheduled task, call or meeting — 3.5's module, not
 * spatie's audit entry, which is `History`. The two words collide throughout
 * this codebase; see .ai/rules/activities.md.
 */
enum TimelineEntryKind: string
{
    case Note = 'note';
    case Document = 'document';
    case Activity = 'activity';
    case History = 'history';
    /**
     * Email, SMS, WhatsApp and website chat — everything the application sent
     * to the customer or received from them.
     *
     * Calls are not here. A call in this system is a scheduled activity and is
     * already on the timeline as one; a second strand for it would put every
     * call on the page twice.
     */
    case Communication = 'communication';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Document => 'Document',
            self::Activity => 'Activity',
            self::History => 'History',
            self::Communication => 'Message',
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
            self::Activity => 'Activities',
            self::History => 'History',
            self::Communication => 'Messages',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Note => 'lucide-message-square-text',
            self::Document => 'lucide-paperclip',
            self::Activity => 'lucide-calendar-clock',
            self::History => 'lucide-history',
            self::Communication => 'lucide-send',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Note => 'amber',
            self::Document => 'cyan',
            // The module's own colour, so a meeting reads the same here as it
            // does on the calendar and in the navigation.
            self::Activity => 'violet',
            self::History => 'slate',
            self::Communication => 'blue',
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
