<?php

namespace App\Domain\Activities\Enums;

/**
 * How urgent an activity is.
 *
 * Backed by an **integer rank**, which is the one place this module departs
 * from the string-backed enums used everywhere else. Priority is the only
 * ordered value in the application: sorting the list by it has to put Urgent
 * above High, and a string column sorts "high, low, normal, urgent"
 * alphabetically — visibly wrong on screen. The alternative, a second
 * `priority_rank` column, is a denormalised flag that can disagree with the
 * value it ranks.
 *
 * A rank is not an id, but renumbering still means a migration, so add new
 * cases at the end unless the order itself genuinely changes.
 */
enum ActivityPriority: int
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

    /**
     * Whether this priority is worth drawing attention to in a list.
     */
    public function isElevated(): bool
    {
        return $this->value >= self::High->value;
    }

    /**
     * Keyed by the stored rank. The keys are written as strings because that is
     * what a dropdown and a filter condition carry, but PHP turns a numeric
     * string key straight back into an int — so unlike every other enum here,
     * which is string-backed, this map really is int-keyed.
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
