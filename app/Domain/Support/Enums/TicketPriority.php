<?php

namespace App\Domain\Support\Enums;

/**
 * How badly it hurts.
 *
 * Backed by an **integer rank** for the reason ActivityPriority is: sorting a
 * queue by priority has to put Urgent above High, and a string column sorts
 * "high, low, normal, urgent" alphabetically — visibly wrong on the one screen
 * an agent lives in. See .ai/rules/activities.md.
 */
enum TicketPriority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
    case Urgent = 4;

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'slate',
            self::Normal => 'blue',
            self::High => 'amber',
            self::Urgent => 'rose',
        };
    }

    public function isElevated(): bool
    {
        return $this->value >= self::High->value;
    }

    /**
     * Keyed by the stored rank. The keys are written as strings because that is
     * what a dropdown and a filter condition carry, but PHP turns a numeric
     * string key straight back into an int — so unlike the other two enums
     * here, which are string-backed, this map really is int-keyed.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }
}
