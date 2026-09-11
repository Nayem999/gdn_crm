<?php

namespace App\Domain\Approvals\Enums;

/**
 * Where an approval request, or one level of it, stands.
 *
 * One enum for both, as with workflow runs: a level and a request answer the
 * same question, and two enums with the same cases would allow a request to be
 * approved while holding a level in a state a request cannot be in.
 *
 * `Expired` is kept apart from `Rejected` on purpose. Both stop the workflow,
 * but "somebody considered this and said no" and "nobody answered in time" are
 * different facts, and an audit of a decision is exactly where that difference
 * matters.
 */
enum ApprovalStatus: string
{
    case Waiting = 'waiting';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'No answer in time',
            self::Cancelled => 'Withdrawn',
        };
    }

    /**
     * The palette key the status chip renders. See ChipPalette.
     */
    public function color(): string
    {
        return match ($this) {
            self::Waiting => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
            self::Expired, self::Cancelled => 'slate',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Waiting;
    }

    /**
     * Whether the workflow that asked may carry on.
     */
    public function letsWorkThrough(): bool
    {
        return $this === self::Approved;
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
