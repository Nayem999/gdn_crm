<?php

namespace App\Domain\Support\Enums;

/**
 * Where a ticket has got to.
 *
 * Six, and the two at the end are not the same thing: **resolved** is "we think
 * it is fixed", **closed** is "nobody is coming back to it". Support teams are
 * measured on the gap between them, and a single "done" would throw that away.
 *
 * `Pending` and `OnHold` are also deliberately separate. Pending is waiting on
 * the customer and the clock keeps running against us in neither case — but
 * 9.3's SLA pauses on hold and not on pending, because waiting for a supplier
 * is our problem and waiting for a reply is theirs.
 */
enum TicketStatus: string
{
    case New = 'new';
    case Open = 'open';
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Open => 'Open',
            self::Pending => 'Waiting on customer',
            self::OnHold => 'On hold',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'rose',
            self::Open => 'amber',
            self::Pending => 'violet',
            self::OnHold => 'slate',
            self::Resolved => 'emerald',
            self::Closed => 'blue',
        };
    }

    /**
     * Whether the customer is still waiting on us.
     */
    public function isOpen(): bool
    {
        return ! $this->isSettled();
    }

    public function isSettled(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    /**
     * Whether reaching this status stamps `resolved_at`.
     *
     * Closing stamps it too: a ticket closed without ever being marked resolved
     * was still resolved at that moment, and a resolution time that ignored
     * those would flatter every report in 9.5.
     */
    public function marksResolved(): bool
    {
        return $this->isSettled();
    }

    /**
     * The statuses still on somebody's queue, for the scopes that ask.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isOpen())
        ));
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
