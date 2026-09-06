<?php

namespace App\Domain\Shared\Imports;

/**
 * How far an import run has got.
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Running => 'Importing',
            self::Completed => 'Finished',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Running => 'blue',
            self::Completed => 'emerald',
            self::Failed => 'rose',
        };
    }

    /**
     * Whether the run is still going, which is what the screen polls on.
     */
    public function isSettled(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
