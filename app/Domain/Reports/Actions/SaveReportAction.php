<?php

namespace App\Domain\Reports\Actions;

use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportSources;
use App\Models\User;
use RuntimeException;

/**
 * Stores a question.
 *
 * The definition is normalised through ReportDefinition on the way in, so what
 * lands in the column is keys the registry knows — a request cannot post extra
 * JSON into the row and have it come back out at run time.
 */
class SaveReportAction
{
    /**
     * @throws RuntimeException when the source is not one the registry offers
     */
    public function __invoke(
        Report $report,
        string $name,
        ReportDefinition $definition,
        User $actor,
        ?string $description = null,
        string $chartType = 'table',
        bool $shared = false,
    ): Report {
        if (! ReportSources::has($definition->source)) {
            throw new RuntimeException('That is not something this application can report on.');
        }

        $report->forceFill([
            'name' => $name,
            'description' => $description,
            'source' => $definition->source,
            // Round-tripped through the DTO rather than stored as posted: the
            // column holds what the engine will actually read.
            'definition' => $definition->toArray(),
            'chart_type' => $chartType,
            'owner_id' => $report->owner_id ?? $actor->id,
            'is_shared' => $shared,
        ])->save();

        return $report->refresh();
    }
}
