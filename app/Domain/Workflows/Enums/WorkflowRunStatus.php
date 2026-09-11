<?php

namespace App\Domain\Workflows\Enums;

/**
 * How a run, or one step of it, ended.
 *
 * One enum for both. A step and a run answer the same question — what happened
 * — and two enums with the same five cases would only create the chance for a
 * run to be "success" while holding a step in a state a run cannot be in.
 *
 * `Skipped` is deliberately not a failure: a workflow whose conditions did not
 * match did its job. Counting those as failures would bury the real ones in the
 * health figures 5.8 reports. `Rejected` is not a failure either — somebody
 * considered the request and said no, and the workflow stopping is it working.
 *
 * `AwaitingApproval` is the one non-terminal state a run can sit in for days.
 * It is not retryable: what it needs is a person, not another attempt.
 */
enum WorkflowRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Success = 'success';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Running => 'Running',
            self::AwaitingApproval => 'Waiting for approval',
            self::Success => 'Succeeded',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * The palette key the status chip renders. See ChipPalette.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Running => 'blue',
            self::AwaitingApproval => 'amber',
            self::Success => 'emerald',
            self::Skipped => 'amber',
            self::Failed => 'rose',
            self::Rejected => 'rose',
        };
    }

    /**
     * Whether this run is over, either way.
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Success, self::Skipped, self::Failed, self::Rejected => true,
            default => false,
        };
    }

    /**
     * Whether re-running it could produce a different outcome — which is what
     * 5.8's retry button offers, and what it should refuse for everything else.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
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
