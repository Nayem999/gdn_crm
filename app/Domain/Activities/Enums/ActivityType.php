<?php

namespace App\Domain\Activities\Enums;

/**
 * What kind of activity this is.
 *
 * One table holds all three because every screen wants them merged — "what is
 * due today" is not a question about tasks alone — and they differ only in
 * which optional fields make sense.
 */
enum ActivityType: string
{
    case Task = 'task';
    case Call = 'call';
    case Meeting = 'meeting';

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Task',
            self::Call => 'Call',
            self::Meeting => 'Meeting',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Task => 'violet',
            self::Call => 'blue',
            self::Meeting => 'amber',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Task => 'check-square',
            self::Call => 'phone',
            self::Meeting => 'users',
        };
    }

    /**
     * Whether a duration is worth asking for. A task takes as long as it takes;
     * a call and a meeting occupy a slot in the calendar 3.6 draws.
     */
    public function hasDuration(): bool
    {
        return $this !== self::Task;
    }

    /**
     * Whether somewhere to be is meaningful.
     */
    public function hasLocation(): bool
    {
        return $this === self::Meeting;
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
