<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Models\Report;
use App\Models\User;

/**
 * A copy to work on.
 *
 * The obvious way to build a report: start from one that nearly does it. The
 * copy belongs to whoever made it, is private until they say otherwise, and is
 * never standard — a duplicate of a built-in is somebody's own report, and
 * would otherwise be undeletable.
 */
class DuplicateReportAction
{
    public function __invoke(Report $report, User $actor): Report
    {
        $copy = new Report;

        $copy->forceFill([
            'name' => $report->name.' (copy)',
            'description' => $report->description,
            'source' => $report->source,
            'definition' => $report->definition,
            'chart_type' => $report->chart_type,
            'owner_id' => $actor->id,
            'is_shared' => false,
            'is_standard' => false,
            'slug' => null,
        ])->save();

        return $copy->refresh();
    }
}
