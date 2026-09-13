<?php

namespace App\Domain\Ingestion\Enums;

/**
 * Where one delivery has got to.
 *
 * `Received` is written the moment the body is captured and before anything
 * looks at it, which is the point: a payload that crashes the processor still
 * leaves a row saying it arrived. Everything after that is the pipeline's
 * doing (8.4).
 *
 * `Skipped` is not a failure. A source that filters out everything but one
 * event type discards most of what it is sent, and calling that an error would
 * bury the real ones in the log 8.9 draws.
 */
enum IntegrationEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processing => 'Processing',
            self::Processed => 'Processed',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received => 'slate',
            self::Processing => 'blue',
            self::Processed => 'emerald',
            self::Skipped => 'amber',
            self::Failed => 'rose',
        };
    }

    /**
     * Whether the pipeline is finished with this one, one way or another.
     */
    public function isSettled(): bool
    {
        return $this === self::Processed || $this === self::Skipped || $this === self::Failed;
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
