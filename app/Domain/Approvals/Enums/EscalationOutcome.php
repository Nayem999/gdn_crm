<?php

namespace App\Domain\Approvals\Enums;

/**
 * What happens when a level runs out of time.
 *
 * Configured per approval step rather than assumed, because the right answer
 * depends entirely on what is being approved. A discount above the limit should
 * *not* approve itself because a manager was on holiday; a courtesy sign-off on
 * a routine renewal probably should, rather than stall the work.
 *
 * `Escalate` passes it to the next person in the chain, which is what most
 * people mean by escalation — and when there is nobody left it rejects, because
 * silence is not agreement.
 */
enum EscalationOutcome: string
{
    case Escalate = 'escalate';
    case Approve = 'approve';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Escalate => 'Ask the next person',
            self::Approve => 'Approve it anyway',
            self::Reject => 'Reject it',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Escalate => 'Move to the next approver. If there is nobody left, reject — silence is not agreement.',
            self::Approve => 'Let it through. Only for approvals that exist to be recorded rather than to gate.',
            self::Reject => 'Stop the workflow.',
        };
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
