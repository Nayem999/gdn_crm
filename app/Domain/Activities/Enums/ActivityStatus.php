<?php

namespace App\Domain\Activities\Enums;

/**
 * Where an activity has got to.
 *
 * Overdue is deliberately not a case: it is a due date in the past on something
 * still open, so storing it would mean a nightly job rewriting rows and a window
 * in which the column disagreed with the clock.
 */
enum ActivityStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'slate',
            self::Completed => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isClosed(): bool
    {
        return ! $this->isOpen();
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
