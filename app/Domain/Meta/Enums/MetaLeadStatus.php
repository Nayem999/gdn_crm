<?php

namespace App\Domain\Meta\Enums;

/**
 * What became of one lead-ad submission.
 *
 * Deliberately the same four states `IntegrationEventStatus` uses, because a
 * Meta lead *is* a delivery and somebody reading the two side by side should
 * not have to translate. What this adds is that the state survives the event
 * log's retention: a `meta_leads` row is the lasting answer to "did that
 * submission reach us", long after the delivery it arrived in has been pruned.
 */
enum MetaLeadStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    /**
     * Recorded and deliberately not written: a matched lead the source's
     * duplicate policy says to leave alone, or a page this installation does
     * not manage. Not an error — a skipped submission is one somebody decided
     * about, and calling it a failure buries the ones that are.
     */
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processed => 'Processed',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received => 'slate',
            self::Processed => 'emerald',
            self::Skipped => 'amber',
            self::Failed => 'rose',
        };
    }

    /**
     * Whether this submission is finished with. A settled row is never
     * reprocessed — that is what stops a replayed delivery creating a second
     * copy of the lead the first one made.
     */
    public function isSettled(): bool
    {
        return $this !== self::Received;
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
