<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Models\Report;
use RuntimeException;

class DeleteReportAction
{
    /**
     * Soft-deleted, like everything else that somebody may have linked to.
     *
     * @throws RuntimeException when the report is one of the built-in ones
     */
    public function __invoke(Report $report): void
    {
        if ($report->is_standard) {
            throw new RuntimeException('A standard report cannot be removed.');
        }

        $report->delete();
    }
}
