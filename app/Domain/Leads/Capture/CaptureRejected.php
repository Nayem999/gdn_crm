<?php

namespace App\Domain\Leads\Capture;

use RuntimeException;

/**
 * A submission that will not become a lead.
 *
 * Carries a reason for the log, and a flag for whether the visitor should be
 * told. A spam rejection deliberately looks like success on screen: telling a
 * bot which signal caught it is telling whoever wrote it what to change.
 */
class CaptureRejected extends RuntimeException
{
    public function __construct(
        string $reason,
        public readonly bool $silent = true,
    ) {
        parent::__construct($reason);
    }
}
