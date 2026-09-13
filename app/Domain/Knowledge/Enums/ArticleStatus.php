<?php

namespace App\Domain\Knowledge\Enums;

/**
 * Where an article has got to.
 *
 * Archived is not the same as removed: an article about a product nobody sells
 * any more is still the answer for the customers who still have one, and a desk
 * wants it out of the way without losing it.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Published => 'emerald',
            self::Archived => 'slate',
        };
    }

    /**
     * Whether somebody who is not an author can read it.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
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
