<?php

namespace App\Domain\Mail\Enums;

/**
 * Where a message got to.
 *
 * The order matters and is encoded in rank(): a message goes forward through
 * sent → delivered → opened → clicked, and a webhook that arrives out of order
 * — they do — must not walk it backwards. A "delivered" landing after an "open"
 * is old news, not a demotion.
 *
 * Failure is not on that scale at all. Bounced, complained and failed always
 * win, because they are what somebody needs to see: a message that was opened
 * and then complained about is a complaint.
 */
enum EmailStatus: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Opened => 'Opened',
            self::Clicked => 'Clicked',
            self::Bounced => 'Bounced',
            self::Complained => 'Marked as spam',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'slate',
            self::Delivered => 'blue',
            self::Opened => 'cyan',
            self::Clicked => 'emerald',
            self::Bounced => 'rose',
            self::Complained => 'fuchsia',
            self::Failed => 'amber',
        };
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::Bounced, self::Complained, self::Failed], true);
    }

    /**
     * How far along the delivery this is. Only meaningful between two
     * non-failure statuses.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Sent => 1,
            self::Delivered => 2,
            self::Opened => 3,
            self::Clicked => 4,
            self::Failed => 5,
            self::Bounced => 6,
            self::Complained => 7,
        };
    }

    /**
     * The status a message should hold once this one has happened too.
     */
    public function then(self $next): self
    {
        if ($this->isFailure() && ! $next->isFailure()) {
            return $this;
        }

        if ($next->isFailure() && ! $this->isFailure()) {
            return $next;
        }

        return $next->rank() > $this->rank() ? $next : $this;
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
